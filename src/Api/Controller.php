<?php

namespace ErnestDefoe\MemberDesk\Api;

use ErnestDefoe\MemberDesk\Access;
use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use ErnestDefoe\MemberDesk\Audit\Recorder;
use ErnestDefoe\MemberDesk\Bulk;
use ErnestDefoe\MemberDesk\Log;
use ErnestDefoe\MemberDesk\MemberDetail;
use ErnestDefoe\MemberDesk\MemberQuery;
use ErnestDefoe\MemberDesk\Optional;
use ErnestDefoe\MemberDesk\Presenter;
use ErnestDefoe\MemberDesk\SavedFilter;
use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Member Desk's API: plain JSON under /api/member-desk, one route name per method
 * (`member-desk.<method>`). Every method checks Access first; nothing here is reachable by a
 * member without `ernestdefoe-member-desk.view` (or admin).
 *
 * GET    /member-desk/members            list (filters, sort, page)
 * GET    /member-desk/members/{id}       member page
 * POST   /member-desk/preview            confirmation numbers for a bulk action
 * POST   /member-desk/run                run a bulk (or single) action
 * GET    /member-desk/batches/{batch}    a queued batch's progress
 * GET    /member-desk/export             CSV of the filter or selection (streamed)
 * GET    /member-desk/log                the log
 * POST   /member-desk/hash-check         "was it this email/IP?" against erased members
 * POST   /member-desk/ban-ip             ban one IP (fof/ban-ips)
 * GET/POST /member-desk/filters, PATCH/DELETE /member-desk/filters/{id}
 */
class Controller implements RequestHandlerInterface
{
    public function __construct(
        protected Access $access,
        protected Optional $optional,
        protected MemberQuery $query,
        protected Presenter $presenter,
        protected MemberDetail $detail,
        protected Bulk $bulk,
        protected Recorder $recorder,
        protected ConnectionInterface $db,
        protected \ErnestDefoe\MemberDesk\BanIps $bans,
        protected Log $logPresenter,
        protected \ErnestDefoe\MemberDesk\Reports $reports,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $name = (string) $request->getAttribute('routeName');
        $method = substr($name, strrpos($name, '.') + 1);
        $actor = RequestUtil::getActor($request);
        $this->access->assertView($actor);

        if (! in_array($method, ['list', 'show', 'preview', 'run', 'batch', 'export', 'log', 'hashCheck', 'banIp', 'filters', 'createFilter', 'updateFilter', 'deleteFilter'], true)) {
            throw new \RuntimeException('No method '.$method);
        }

        $result = $this->{$method}($request, $actor);

        return $result instanceof ResponseInterface ? $result : ($result === null ? new EmptyResponse(204) : new JsonResponse($result));
    }

    /** @return array<string, mixed> */
    protected function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    protected function id(ServerRequestInterface $request): int
    {
        return (int) Arr::get($request->getAttribute('routeParameters', []), 'id');
    }

    /** @return array<string, mixed> */
    protected function list(ServerRequestInterface $request, User $actor): array
    {
        $params = $request->getQueryParams();
        $perPage = max(10, min(100, (int) ($params['perPage'] ?? 50)));
        $page = max(1, (int) ($params['page'] ?? 1));

        $query = $this->query->build($actor, $params);
        $total = (clone $query)->toBase()->getCountForPagination();

        $this->query->sort($query, $actor, $params['sort'] ?? null, $params['dir'] ?? null);
        $this->reports->addCounts($query, $actor);
        $members = $query->with('groups')->forPage($page, $perPage)->get();

        return [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'rows' => $this->presenter->rows($actor, $members),
        ] + ($page === 1 && empty($params['rowsOnly']) ? $this->meta($actor) : []);
    }

    /** What the page needs once: groups, switches, the actor's abilities, saved filters. */
    protected function meta(User $actor): array
    {
        $groups = Group::query()->whereNotIn('id', [Group::GUEST_ID, Group::MEMBER_ID])->orderBy('id')->get()
            ->filter(fn (Group $g) => ! $g->is_hidden || $actor->can('viewHiddenGroups'))
            ->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name_singular, 'plural' => $g->name_plural, 'color' => $g->color, 'icon' => $g->icon])
            ->values()->all();

        return [
            'groups' => $groups,
            'extensions' => $this->optional->all(),
            'can' => $this->access->forActor($actor) + ['lastSeen' => $this->access->lastSeen($actor)],
            'reports' => $this->reports->source($actor),
            'trustLevels' => $this->access->trust($actor)
                ? array_map(fn ($l) => ['level' => $l, 'name' => resolve(\ErnestDefoe\TrustLevels\Names::class)->name($l)], range(0, 4))
                : null,
            'filters' => $this->filterList($actor),
            'gdprDeletionOff' => $this->optional->gdpr() && ! $this->access->gdprAllows('deletion'),
        ];
    }

    /** @return array<string, mixed> */
    protected function show(ServerRequestInterface $request, User $actor): array
    {
        $member = User::query()->findOrFail($this->id($request));

        return $this->detail->build($actor, $member) + ['meta' => $this->meta($actor)];
    }

    /** @return array<string, mixed> */
    protected function preview(ServerRequestInterface $request, User $actor): array
    {
        $b = $this->body($request);
        $action = (string) ($b['action'] ?? '');
        if ($action === 'export' && ! $this->access->export($actor)) {
            throw new PermissionDeniedException();
        }

        return $this->bulk->preview($actor, $action, (array) ($b['target'] ?? []), (array) ($b['params'] ?? []));
    }

    /** @return array<string, mixed> */
    protected function run(ServerRequestInterface $request, User $actor): array
    {
        $b = $this->body($request);
        $action = (string) ($b['action'] ?? '');
        $target = (array) ($b['target'] ?? []);

        // The count the staff member confirmed must still be the count that runs.
        if (isset($b['expect'])) {
            $now = count($this->bulk->resolve($actor, $target));
            if ($now !== (int) $b['expect']) {
                throw new ValidationException(['expect' => 'The list changed since you confirmed ('.$now.' now). Check it and try again.']);
            }
        }

        return $this->bulk->run($actor, $action, $target, (array) ($b['params'] ?? []));
    }

    /** @return array<string, mixed>|null */
    protected function batch(ServerRequestInterface $request, User $actor): ?array
    {
        $batch = (string) Arr::get($request->getAttribute('routeParameters', []), 'batch');

        return $this->bulk->status($actor, $batch) ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
    }

    protected function export(ServerRequestInterface $request, User $actor): ResponseInterface
    {
        if (! $this->access->export($actor)) {
            throw new PermissionDeniedException();
        }

        return resolve(Export::class)->respond($actor, $request->getQueryParams());
    }

    /** @return array<string, mixed> */
    protected function log(ServerRequestInterface $request, User $actor): array
    {
        $p = $request->getQueryParams();
        $page = max(1, (int) ($p['page'] ?? 1));
        $q = AuditEntry::query()->with(['actor', 'member']);
        if (! empty($p['action'])) {
            $q->where('action', (string) $p['action']);
        }
        if (! empty($p['actor'])) {
            $q->where('actor_id', (int) $p['actor']);
        }
        if (! empty($p['user'])) {
            $q->where('user_id', (int) $p['user']);
        }
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('id')->forPage($page, 50)->get();

        return [
            'total' => $total,
            'page' => $page,
            'rows' => $rows->map(fn (AuditEntry $e) => $this->logPresenter->present($e, $actor))->all(),
            'retentionDays' => (int) (resolve(\Flarum\Settings\SettingsRepositoryInterface::class)->get('ernestdefoe-member-desk.retention_days') ?: 90),
        ];
    }

    /** @return array<string, mixed> */
    protected function hashCheck(ServerRequestInterface $request, User $actor): array
    {
        if (! $actor->isAdmin()) {
            throw new PermissionDeniedException();
        }
        $value = trim((string) ($this->body($request)['value'] ?? ''));
        if ($value === '' || mb_strlen($value) > 255) {
            throw new ValidationException(['value' => 'Enter an email address or IP.']);
        }
        $hash = $this->recorder->hash($value);

        $matches = AuditEntry::query()->where('erased', true)->whereNotNull('before')->orderByDesc('id')->limit(5000)->get()
            ->filter(fn (AuditEntry $e) => ($e->before['emailHash'] ?? null) === $hash || in_array($hash, $e->before['ipHashes'] ?? [], true))
            ->take(20)
            ->map(fn (AuditEntry $e) => ['id' => $e->id, 'action' => $e->action, 'userId' => $e->user_id, 'username' => $e->before['username'] ?? null, 'createdAt' => $e->created_at->toIso8601String()])
            ->values()->all();

        $this->recorder->record($actor, 'hashCheck', null, null, ['matches' => count($matches)]);

        return ['matches' => $matches];
    }

    protected function banIp(ServerRequestInterface $request, User $actor): ?array
    {
        if (! $this->optional->banIps() || ! $this->access->ips($actor) || ! $actor->hasPermission('fof.ban-ips.banIP')) {
            throw new PermissionDeniedException();
        }
        $b = $this->body($request);
        $ip = (string) ($b['ip'] ?? '');
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ValidationException(['ip' => 'Not an IP address.']);
        }
        $userId = isset($b['userId']) ? (int) $b['userId'] : null;
        $member = $userId ? User::query()->with('groups')->find($userId) : null;
        if ($member && ($member->id === $actor->id || $this->access->isAdmin($member))) {
            throw new PermissionDeniedException();
        }
        if ($this->bans->actorUses($actor, $ip)) {
            throw new ValidationException(['ip' => 'That is one of your own addresses.']);
        }
        if ($this->db->table('banned_ips')->where('address', $ip)->exists()) {
            return null;
        }

        // Through fof/ban-ips' own API: its permission check, validation and hooks decide.
        $this->bans->ban($actor, $ip, $member?->id, mb_substr(trim((string) ($b['reason'] ?? '')), 0, 190) ?: 'Banned from Member Desk');

        $this->recorder->record($actor, 'banIp', $member?->id, null, ['ipHash' => $this->recorder->hash($ip)]);

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    protected function filterList(User $actor): array
    {
        return SavedFilter::query()
            ->where(fn ($w) => $w->where('shared', true)->orWhere('user_id', $actor->id))
            ->orderBy('position')->orderBy('id')->get()
            ->map(fn (SavedFilter $f) => $f->present($actor))->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function filters(ServerRequestInterface $request, User $actor): array
    {
        return $this->filterList($actor);
    }

    /** @return array<int, array<string, mixed>> */
    protected function createFilter(ServerRequestInterface $request, User $actor): array
    {
        $b = $this->body($request);
        $f = new SavedFilter();
        $f->user_id = $actor->id;
        $this->fillFilter($f, $b, $actor);
        $f->position = (int) SavedFilter::query()->max('position') + 1;
        $f->created_at = Carbon::now();
        $f->save();

        return $this->filterList($actor);
    }

    /** @return array<int, array<string, mixed>> */
    protected function updateFilter(ServerRequestInterface $request, User $actor): array
    {
        $f = $this->ownFilter($request, $actor);
        $this->fillFilter($f, $this->body($request), $actor, true);
        $f->save();

        return $this->filterList($actor);
    }

    /** @return array<int, array<string, mixed>> */
    protected function deleteFilter(ServerRequestInterface $request, User $actor): array
    {
        $this->ownFilter($request, $actor)->delete();

        return $this->filterList($actor);
    }

    protected function ownFilter(ServerRequestInterface $request, User $actor): SavedFilter
    {
        $f = SavedFilter::query()->findOrFail($this->id($request));
        // Your own, or a shared one if you're an admin.
        if ($f->user_id !== $actor->id && ! ($f->shared && $actor->isAdmin())) {
            throw new PermissionDeniedException();
        }

        return $f;
    }

    /** @param array<string, mixed> $b */
    protected function fillFilter(SavedFilter $f, array $b, User $actor, bool $partial = false): void
    {
        if (! $partial || array_key_exists('name', $b)) {
            $name = trim((string) ($b['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) {
                throw new ValidationException(['name' => 'Give the filter a name (up to 80 characters).']);
            }
            $f->name = $name;
        }
        if (! $partial || array_key_exists('query', $b)) {
            $q = Arr::only((array) ($b['query'] ?? []), ['q', 'groups', 'status', 'joinedFrom', 'joinedTo', 'seenFrom', 'seenTo', 'joinedWithin', 'notSeenFor', 'minPosts', 'maxPosts', 'reportedMin', 'reportedWithin', 'trust', 'sort', 'dir']);
            $f->query = json_encode($q) ?: '{}';
        }
        if (array_key_exists('shared', $b)) {
            // Only admins share filters with every staff member.
            $f->shared = (bool) $b['shared'] && $actor->isAdmin();
        }
        if (array_key_exists('position', $b)) {
            $f->position = max(0, (int) $b['position']);
        }
    }
}
