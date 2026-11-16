<?php

namespace ErnestDefoe\MemberDesk;

use Carbon\Carbon;
use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use ErnestDefoe\MemberDesk\Audit\Recorder;
use Flarum\Api\Resource\UserResource;
use Flarum\Discussion\Discussion;
use Flarum\Foundation\ValidationException;
use Flarum\Http\AccessToken;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * Runs one action on one member. Everything that core or another extension already owns goes
 * through Flarum's own API pipeline as the acting staff member, so their permission checks,
 * events and notifications run exactly as if the action were taken in their own UI:
 *
 * - groups: core's user update (editGroups; only admins touch the Admin group);
 * - suspend: flarum/suspend's fields on the user update (its SavingUser listener sends the
 *   Suspended/Unsuspended events and notifications);
 * - anonymize / delete: core's user DELETE, which flarum/gdpr replaces with its erasure when it's
 *   installed (`gdprMode` in the body, exactly like gdpr's own modal). We never re-implement it.
 *
 * Every action is logged BEFORE it runs, so the "as they were" snapshot exists even if it fails.
 */
class Actions
{
    public const FOREVER = '2038-01-01T00:00:00+00:00';

    public function __construct(
        protected ApiCall $call,
        protected BanIps $bans,
        protected Recorder $recorder,
        protected Access $access,
        protected Optional $optional,
        protected ConnectionInterface $db,
        protected Dispatcher $events,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function apply(User $actor, User $member, string $action, array $params, ?string $batch = null): void
    {
        $group = isset($params['group']) ? (int) $params['group'] : null;

        if (! $this->access->action($actor, $action)) {
            throw new ValidationException(['action' => 'not allowed']);
        }
        if ($reason = $this->access->skipReason($actor, $member, $action, $group)) {
            throw new ValidationException(['member' => $reason]);
        }

        match ($action) {
            'addGroup', 'removeGroup' => $this->groups($actor, $member, $action, (int) $group, $batch),
            'suspend' => $this->suspend($actor, $member, $params, $batch),
            'unsuspend' => $this->unsuspend($actor, $member, $batch),
            'confirm' => $this->confirm($actor, $member, $batch),
            'signOut' => $this->signOut($actor, $member, $batch),
            'spammer' => $this->spammer($actor, $member, $batch),
            'unspam' => $this->unspam($actor, $member, $batch),
            'anonymize' => $this->erase($actor, $member, 'anonymization', $batch),
            'delete' => $this->erase($actor, $member, 'deletion', $batch),
            default => throw new ValidationException(['action' => 'unknown']),
        };
    }

    /**
     * Through core's own user update (see ApiCall): core finds the member, runs its `can` checks
     * and every extension's hooks, exactly as for a request from the browser.
     *
     * @param array<string, mixed> $data attributes and/or relationships
     */
    protected function update(User $actor, User $member, array $data): void
    {
        $this->user($actor, 'update', 'PATCH', $member, ['data' => ['type' => 'users', 'id' => (string) $member->id] + $data]);
    }

    /** @param array<string, mixed> $body */
    protected function user(User $actor, string $endpoint, string $method, User $member, array $body): void
    {
        $this->call->send($actor, UserResource::class, $endpoint, $method, '/users/'.$member->id, $body);
    }

    /** @return array<int, array{id: int, name: string}> */
    protected function groupList(User $member): array
    {
        return $member->groups()->get()->map(fn ($g) => ['id' => (int) $g->id, 'name' => (string) $g->name_singular])->all();
    }

    protected function groups(User $actor, User $member, string $action, int $group, ?string $batch): void
    {
        if (! \Flarum\Group\Group::query()->whereKey($group)->exists() || $group === \Flarum\Group\Group::MEMBER_ID || $group === \Flarum\Group\Group::GUEST_ID) {
            throw new ValidationException(['group' => 'unknown']);
        }

        $before = $this->groupList($member);
        $ids = array_column($before, 'id');
        $has = in_array($group, $ids, true);

        if (($action === 'addGroup' && $has) || ($action === 'removeGroup' && ! $has)) {
            return; // nothing to change, nothing to log
        }

        $ids = $action === 'addGroup' ? [...$ids, $group] : array_values(array_diff($ids, [$group]));

        $entry = $this->recorder->record($actor, $action, $member->id, ['groups' => $before], ['group' => $group], $batch, 'running');

        $this->update($actor, $member, ['relationships' => ['groups' => ['data' => array_map(fn ($id) => ['type' => 'groups', 'id' => (string) $id], $ids)]]]);

        $this->finish($entry, ['group' => $group, 'groups' => $this->groupList($member->fresh() ?? $member)]);
    }

    /** @param array<string, mixed> $params */
    protected function suspend(User $actor, User $member, array $params, ?string $batch): void
    {
        $until = $this->until($params['until'] ?? 'forever');
        $reason = mb_substr(trim((string) ($params['reason'] ?? '')), 0, 2000) ?: null;
        $message = mb_substr(trim((string) ($params['message'] ?? '')), 0, 2000) ?: null;

        $entry = $this->recorder->record($actor, 'suspend', $member->id, ['suspendedUntil' => $this->currentSuspension($member)], ['until' => $until, 'reason' => $reason], $batch, 'running');

        $this->update($actor, $member, ['attributes' => ['suspendedUntil' => $until, 'suspendReason' => $reason, 'suspendMessage' => $message]]);

        $this->finish($entry);
    }

    protected function unsuspend(User $actor, User $member, ?string $batch): void
    {
        $was = $this->currentSuspension($member);
        if (! $was) {
            return;
        }

        $entry = $this->recorder->record($actor, 'unsuspend', $member->id, ['suspendedUntil' => $was], null, $batch, 'running');
        $this->update($actor, $member, ['attributes' => ['suspendedUntil' => null, 'suspendReason' => null, 'suspendMessage' => null]]);
        $this->finish($entry);
    }

    protected function currentSuspension(User $member): ?string
    {
        $until = $member->getAttribute('suspended_until');
        if (! $until) {
            return null;
        }
        $until = $until instanceof Carbon ? $until : Carbon::parse($until);

        return $until->isFuture() ? $until->toIso8601String() : null;
    }

    protected function until(mixed $value): string
    {
        if ($value === 'forever' || $value === null || $value === '') {
            return self::FOREVER;
        }
        if (is_numeric($value)) {
            $days = max(1, min(3650, (int) $value));

            return Carbon::now()->addDays($days)->toIso8601String();
        }
        try {
            $date = Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new ValidationException(['until' => 'invalid']);
        }
        if (! $date->isFuture()) {
            throw new ValidationException(['until' => 'past']);
        }

        return $date->toIso8601String();
    }

    protected function confirm(User $actor, User $member, ?string $batch): void
    {
        if ($member->is_email_confirmed) {
            return;
        }

        $entry = $this->recorder->record($actor, 'confirm', $member->id, ['emailConfirmed' => false], null, $batch, 'running');
        $member->activate();
        $member->save();
        foreach ($member->releaseEvents() as $event) {
            $this->events->dispatch($event);
        }
        $this->finish($entry, ['emailConfirmed' => true]);
    }

    protected function signOut(User $actor, User $member, ?string $batch): void
    {
        $count = AccessToken::query()->where('user_id', $member->id)->count();
        $entry = $this->recorder->record($actor, 'signOut', $member->id, ['sessions' => $count], null, $batch, 'running');
        AccessToken::query()->where('user_id', $member->id)->delete();
        $this->finish($entry);
    }

    /**
     * Suspend forever, hide every visible post and discussion they started, ban their IPs
     * (fof/ban-ips). What was hidden and banned is written to the log entry, so "Restore" undoes
     * exactly this and nothing a moderator hid separately.
     */
    protected function spammer(User $actor, User $member, ?string $batch): void
    {
        $entry = $this->recorder->record($actor, 'spammer', $member->id, $this->recorder->snapshot($member, false) + ['suspendedUntil' => $this->currentSuspension($member)], null, $batch, 'running');

        $this->update($actor, $member, ['attributes' => [
            'suspendedUntil' => self::FOREVER,
            'suspendReason' => 'Spam (Member Desk)',
            'suspendMessage' => null,
        ]]);

        $posts = $this->setHidden(CommentPost::query()->with(['user', 'discussion'])->where('user_id', $member->id)->whereNull('hidden_at'), $actor);
        $discussions = $this->setHidden(Discussion::query()->with('user')->where('user_id', $member->id)->whereNull('hidden_at'), $actor);
        [$banned, $banSkipped] = $this->optional->banIps() ? $this->banIpsOf($actor, $member) : [[], 0];

        $this->finish($entry, [
            'posts' => $posts,
            'discussions' => $discussions,
            'bannedIps' => $banned,
        ] + ($banSkipped ? ['banSkipped' => $banSkipped] : []));
    }

    /**
     * Hide (as $actor) or, with no actor, restore every post or discussion the query finds, 200 at
     * a time, firing the model's own events. Returns the ids it changed.
     *
     * @param \Illuminate\Database\Eloquent\Builder<CommentPost>|\Illuminate\Database\Eloquent\Builder<Discussion> $query
     * @return int[]
     */
    protected function setHidden(\Illuminate\Database\Eloquent\Builder $query, ?User $actor): array
    {
        $ids = [];
        $query->orderBy('id')->chunkById(200, function ($models) use ($actor, &$ids) {
            foreach ($models as $model) {
                /** @var CommentPost|Discussion $model */
                $actor ? $model->hide($actor) : $model->restore();
                $model->save();
                foreach ($model->releaseEvents() as $event) {
                    $this->events->dispatch($event);
                }
                $ids[] = $model->id;
            }
        });

        return $ids;
    }

    /**
     * Ban the spammer's IPs through fof/ban-ips' own API. Never the acting staff member's own
     * address, or one an active member also uses (a shared home, office or carrier NAT): in a spam
     * ring the shared address is banned once the last account on it has been marked. A staff
     * member fof/ban-ips won't let ban (no banIP permission) still marks the spammer; the IPs just
     * stay unbanned and are counted as skipped.
     *
     * @return array{0: string[], 1: int}
     */
    protected function banIpsOf(User $actor, User $member): array
    {
        $ips = $this->recorder->ipsOf($member->id);
        $already = $ips ? $this->db->table('banned_ips')->whereIn('address', $ips)->pluck('address')->flip()->all() : [];
        $banned = [];
        $skipped = 0;
        foreach ($ips as $ip) {
            if (isset($already[$ip]) || $this->bans->actorUses($actor, $ip) || $this->sharedWithActive($member, $ip)) {
                continue;
            }
            try {
                if ($this->bans->ban($actor, $ip, $member->id, 'Spammer (Member Desk)')) {
                    $banned[] = $ip;
                }
            } catch (\Flarum\User\Exception\PermissionDeniedException|\Flarum\Foundation\ValidationException|\Tobyz\JsonApiServer\Exception\ForbiddenException|\Tobyz\JsonApiServer\Exception\UnprocessableEntityException) {
                $skipped++;
            }
        }

        return [$banned, $skipped];
    }

    protected function sharedWithActive(User $member, string $ip): bool
    {
        $active = fn ($q) => $q->select('id')->from('users')
            ->where(fn ($w) => $w->whereNull('suspended_until')->orWhere('suspended_until', '<', Carbon::now()));

        return $this->db->table('access_tokens')->where('last_ip_address', $ip)->where('user_id', '<>', $member->id)->whereIn('user_id', $active)->exists()
            || $this->db->table('posts')->where('ip_address', $ip)->where('user_id', '<>', $member->id)->whereIn('user_id', $active)->exists();
    }

    /** Undo the member's latest spammer action, exactly as recorded. */
    protected function unspam(User $actor, User $member, ?string $batch): void
    {
        /** @var AuditEntry|null $last */
        $last = AuditEntry::query()->where('user_id', $member->id)->where('action', 'spammer')->where('status', 'done')
            ->orderByDesc('id')->first();
        if (! $last || ! empty($last->after['restored'])) {
            throw new ValidationException(['member' => 'nothing to restore']);
        }

        $after = $last->after ?? [];
        $entry = $this->recorder->record($actor, 'unspam', $member->id, null, ['restores' => $last->id], $batch, 'running');

        $this->setHidden(CommentPost::query()->with(['user', 'discussion'])->whereIn('id', $after['posts'] ?? [])->whereNotNull('hidden_at'), null);
        $this->setHidden(Discussion::query()->with('user')->whereIn('id', $after['discussions'] ?? [])->whereNotNull('hidden_at'), null);

        if (! empty($after['bannedIps'])) {
            $this->bans->unban($actor, $after['bannedIps'], $member->id);
        }

        $previous = $last->before['suspendedUntil'] ?? null;
        $this->update($actor, $member, ['attributes' => [
            'suspendedUntil' => $previous && Carbon::parse($previous)->isFuture() ? $previous : null,
            'suspendReason' => null,
            'suspendMessage' => null,
        ]]);

        $last->after = $after + ['restored' => true];
        $last->save();

        $this->finish($entry);
    }

    /**
     * Anonymize or delete. With flarum/gdpr: its erasure, through its own endpoint (queued; the
     * entry stays "queued" until gdpr's Erased event marks it done). Without: core's delete.
     */
    protected function erase(User $actor, User $member, string $mode, ?string $batch): void
    {
        $gdpr = $this->optional->gdpr();
        $action = $mode === 'deletion' ? 'delete' : 'anonymize';

        $entry = $this->recorder->record(
            $actor,
            $action,
            $member->id,
            $this->recorder->snapshot($member, true),
            ['via' => $gdpr ? 'gdpr' : 'core'],
            $batch,
            $gdpr ? 'queued' : 'running',
            true
        );

        $this->recorder->scrubEarlier($member->id, $entry->id);

        $this->user($actor, 'delete', 'DELETE', $member, $gdpr ? ['gdprMode' => $mode] : []);

        if (! $gdpr) {
            $this->finish($entry);
        } else {
            // On the sync queue gdpr has already finished; the Erased listener marked it done.
            $entry->refresh();
        }
    }

    /** @param array<string, mixed>|null $after */
    protected function finish(AuditEntry $entry, ?array $after = null): void
    {
        if ($after !== null) {
            $entry->after = $after;
        }
        $entry->status = 'done';
        $entry->save();
    }
}
