<?php

namespace ErnestDefoe\MemberDesk;

use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Bulk actions: the confirmation's numbers (preview) and the run. A target is either explicit ids
 * or "every member matching these filters" (resolved on the server with the same MemberQuery the
 * list uses, so the count the admin confirmed is the set that runs).
 *
 * Up to 50 members run inside the request; more go to a queued job in chunks, with progress kept
 * in the cache under the batch id. One member failing never stops the rest.
 */
class Bulk
{
    public const INLINE = 50;

    public const MAX = 20000;

    public function __construct(
        protected MemberQuery $query,
        protected Access $access,
        protected Optional $optional,
        protected Actions $actions,
        protected Cache $cache,
        protected Queue $queue,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * @param array<string, mixed> $target
     * @return int[]
     */
    public function resolve(User $actor, array $target): array
    {
        if (! empty($target['all'])) {
            $q = $this->query->build($actor, (array) Arr::get($target, 'filters', []));

            return $q->orderBy('id')->limit(self::MAX)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $ids = array_values(array_unique(array_map('intval', (array) Arr::get($target, 'ids', []))));
        if (count($ids) > self::MAX) {
            throw new ValidationException(['ids' => 'too many']);
        }

        return $ids;
    }

    /**
     * What the confirmation shows: how many, their names, who is skipped and why, and what
     * content is kept or removed.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function preview(User $actor, string $action, array $target, array $params): array
    {
        if (! $this->access->action($actor, $action) && $action !== 'export') {
            throw new ValidationException(['action' => 'not allowed']);
        }

        $ids = $this->resolve($actor, $target);
        $group = isset($params['group']) ? (int) $params['group'] : null;

        $go = [];
        $skipped = [];
        $posts = 0;
        $discussions = 0;
        $unconfirmed = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $members = User::query()->whereIn('id', $chunk)->with('groups')->get();
            foreach ($members as $m) {
                $reason = $action === 'export' ? null : $this->access->skipReason($actor, $m, $action, $group);
                if ($reason) {
                    $skipped[] = ['id' => $m->id, 'displayName' => $m->display_name, 'reason' => $reason];
                    continue;
                }
                $go[] = $m;
                $posts += (int) $m->comment_count;
                $discussions += (int) $m->discussion_count;
                $unconfirmed += $m->is_email_confirmed ? 0 : 1;
            }
        }

        return [
            'count' => count($go),
            'posts' => $posts,
            'discussions' => $discussions,
            'unconfirmed' => $unconfirmed,
            'names' => array_map(fn (User $m) => ['id' => $m->id, 'displayName' => $m->display_name, 'username' => $m->username], array_slice($go, 0, 60)),
            'skipped' => array_slice($skipped, 0, 60),
            'skippedCount' => count($skipped),
            'gdpr' => $this->optional->gdpr(),
            'modes' => [
                'anonymize' => $this->access->action($actor, 'anonymize'),
                'delete' => $this->access->action($actor, 'delete'),
            ],
            'banIps' => $this->optional->banIps(),
            'queued' => count($go) > self::INLINE,
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function run(User $actor, string $action, array $target, array $params): array
    {
        if (! $this->access->action($actor, $action)) {
            throw new ValidationException(['action' => 'not allowed']);
        }

        $ids = $this->resolve($actor, $target);
        $batch = (string) Str::uuid();

        if (count($ids) <= self::INLINE) {
            $result = $this->runIds($actor, $action, $ids, $params, $batch);

            return ['batch' => $batch, 'queued' => false] + $result;
        }

        $this->progress($batch, ['total' => count($ids), 'done' => 0, 'failed' => [], 'finished' => false, 'actor' => $actor->id]);
        $this->queue->push(new BulkJob($actor->id, $action, $ids, $params, $batch));

        return ['batch' => $batch, 'queued' => true, 'total' => count($ids)];
    }

    /**
     * @param int[] $ids
     * @param array<string, mixed> $params
     * @return array{done: int, failed: array<int, array<string, mixed>>}
     */
    public function runIds(User $actor, string $action, array $ids, array $params, string $batch): array
    {
        $done = 0;
        $failed = [];
        $group = isset($params['group']) ? (int) $params['group'] : null;

        foreach (array_chunk($ids, 100) as $chunk) {
            $members = User::query()->whereIn('id', $chunk)->with('groups')->get()->keyBy('id');
            foreach ($chunk as $id) {
                $m = $members->get($id);
                if (! $m) {
                    continue;
                }
                if ($reason = $this->access->skipReason($actor, $m, $action, $group)) {
                    $failed[] = ['id' => $id, 'displayName' => $m->display_name, 'reason' => $reason];
                    continue;
                }
                try {
                    $this->actions->apply($actor, $m, $action, $params, $batch);
                    $done++;
                } catch (\Throwable $e) {
                    AuditEntry::query()->where('batch', $batch)->where('user_id', $id)->where('status', 'running')->update(['status' => 'failed']);
                    $reason = $this->reason($e);
                    // A refusal (self, admin, permission…) is the answer; anything else is a bug to find.
                    if ($reason === 'error') {
                        $this->log->error('[member-desk] '.$action.' failed for member '.$id.': '.$e->getMessage(), ['exception' => $e]);
                    }
                    $failed[] = ['id' => $id, 'displayName' => $m->display_name, 'reason' => $reason];
                }
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    protected function reason(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $value = (string) (array_values($e->getAttributes())[0] ?? '');

            return match ($value) {
                'self', 'admin', 'anonymized' => $value,
                'nothing to restore' => 'nothing',
                'not allowed' => 'permission',
                default => 'invalid',
            };
        }
        if ($e instanceof \Flarum\User\Exception\PermissionDeniedException) {
            return 'permission';
        }

        return 'error';
    }

    /** @param array<string, mixed> $state */
    public function progress(string $batch, array $state): void
    {
        $this->cache->put('member-desk.batch.'.$batch, $state, 86400);
    }

    /** @return array<string, mixed>|null */
    public function status(User $actor, string $batch): ?array
    {
        $state = $this->cache->get('member-desk.batch.'.$batch);
        if (! is_array($state) || ($state['actor'] ?? null) !== $actor->id) {
            return null;
        }

        return $state;
    }
}
