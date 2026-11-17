<?php

namespace ErnestDefoe\MemberDesk\Api;

use ErnestDefoe\MemberDesk\Access;
use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use ErnestDefoe\MemberDesk\Audit\Recorder;
use ErnestDefoe\MemberDesk\Bulk;
use ErnestDefoe\MemberDesk\MemberDetail;
use ErnestDefoe\MemberDesk\MemberQuery;
use ErnestDefoe\MemberDesk\Optional;
use ErnestDefoe\MemberDesk\Presenter;
use ErnestDefoe\MemberDesk\SavedFilter;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Member Desk's actions and members views: plain JSON under /api/member-desk, one route name per
 * method (`member-desk.<method>`). Every method checks Access first; nothing here is reachable by
 * a member without `ernestdefoe-member-desk.view` (or admin).
 *
 * Saved filters and the log are ordinary JSON:API resources (SavedFilterResource, LogResource).
 * What stays here isn't CRUD on one model, so it has no JSON:API shape:
 * - the list and member page are views over users plus a dozen optional extensions, each part
 *   gated by the viewer's own permissions (emails, IPs, last seen, hidden groups, trust levels);
 *   Flarum's users resource would expose its own fields and relationships beside them;
 * - preview/run/batches are a confirm-then-run action over a filter or a selection, queued when
 *   large, and run through Flarum's own API as the staff member (Actions);
 * - export streams a CSV, hash-check answers yes/no against erased members, ban-ip calls
 *   fof/ban-ips' own API.
 *
 * GET    /member-desk/members            list (filters, sort, page)
 * GET    /member-desk/members/{id}       member page
 * POST   /member-desk/preview            confirmation numbers for a bulk action
 * POST   /member-desk/run                run a bulk (or single) action
 * GET    /member-desk/batches/{batch}    a queued batch's progress
 * GET    /member-desk/export             CSV of the filter or selection (streamed)
 * POST   /member-desk/hash-check         "was it this email/IP?" against erased members
 * POST   /member-desk/ban-ip             ban one IP (fof/ban-ips)
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
        protected \ErnestDefoe\MemberDesk\Reports $reports,
        protected Export $export,
        protected SettingsRepositoryInterface $settings,
        protected Container $container,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $name = (string) $request->getAttribute('routeName');
        $method = substr($name, strrpos($name, '.') + 1);
        $actor = RequestUtil::getActor($request);
        $this->access->assertView($actor);

        if (! in_array($method, ['list', 'show', 'preview', 'run', 'batch', 'export', 'hashCheck', 'banIp'], true)) {
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
                ? $this->trustLevelNames()
                : null,
            'filters' => $this->filterList($actor),
            'retentionDays' => (int) ($this->settings->get('ernestdefoe-member-desk.retention_days') ?: 90),
            'gdprDeletionOff' => $this->optional->gdpr() && ! $this->access->gdprAllows('deletion'),
        ];
    }

    /**
     * Trust Levels' names for levels 0–4. Its class exists only while that extension is
     * installed, so it's made from the container here, after Access::trust() said it's on.
     *
     * @return array<int, array{level: int, name: string}>
     */
    protected function trustLevelNames(): array
    {
        $names = $this->container->make(\ErnestDefoe\TrustLevels\Names::class);

        return array_map(fn ($l) => ['level' => $l, 'name' => $names->name($l)], range(0, 4));
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

        return $this->export->respond($actor, $request->getQueryParams());
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
        // An indexed lookup in member_desk_audit_hashes, then only the matching entries are read.
        $ids = $this->recorder->entriesWithHash($value, 20);
        $matches = ($ids ? AuditEntry::query()->where('erased', true)->whereIn('id', $ids)->orderByDesc('id')->get() : collect())
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
}
