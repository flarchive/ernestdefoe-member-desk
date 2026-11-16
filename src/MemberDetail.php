<?php

namespace ErnestDefoe\MemberDesk;

use Carbon\Carbon;
use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Everything the member page shows, in one payload. Each optional extension's section is only
 * read when that extension is on AND the actor holds its own permission (fof/moderator-notes'
 * user.viewModeratorNotes, fof/moderator-warnings' user.viewWarnings).
 */
class MemberDetail
{
    public function __construct(
        protected Access $access,
        protected Optional $optional,
        protected Presenter $presenter,
        protected ConnectionInterface $db,
        protected Log $logPresenter,
        protected Reports $reports,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * One section of the page. A section that reads another extension's data (its tables, its
     * formatter) must never take the whole page down: if it throws, the page still opens without
     * it and the error is logged with the member and the section, so it can be fixed.
     *
     * @template T
     * @param callable(): T $read
     * @param T $fallback
     * @return T
     */
    protected function section(string $name, User $m, callable $read, mixed $fallback): mixed
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            $this->log->error("[member-desk] the {$name} section failed for member {$m->id}: ".$e->getMessage(), ['exception' => $e]);

            return $fallback;
        }
    }

    /** @return array<string, mixed> */
    public function build(User $actor, User $m): array
    {
        $m->loadMissing('groups');
        $ips = $this->access->ips($actor) ? $this->section('ips', $m, fn () => $this->ips($m), []) : null;
        $lastIp = $this->access->ips($actor) ? ($this->presenter->lastIps([$m->id])[$m->id] ?? null) : null;
        $banned = $ips !== null && collect($ips)->contains('banned', true);

        $out = [
            'member' => $this->presenter->row($actor, $m, $lastIp, $banned),
            'profile' => $this->section('profile', $m, fn () => $this->profile($m),
                ['bio' => null, 'locale' => null, 'discloseOnline' => false, 'fields' => [], 'masquerade' => false]),
            'activity' => $this->section('activity', $m, fn () => $this->activity($actor, $m),
                ['months' => [], 'posts' => [], 'discussions' => [], 'likes' => null]),
            'sessions' => $this->section('sessions', $m, fn () => $this->sessions($actor, $m), []),
            'ips' => $ips,
            'notes' => $this->section('notes', $m, fn () => $this->notes($actor, $m), null),
            'warnings' => $this->section('warnings', $m, fn () => $this->warnings($actor, $m), null),
            'suspension' => $this->section('suspension', $m, fn () => $this->suspension($m), null),
            'log' => $this->section('log', $m, fn () => $this->log($actor, $m), []),
            'erasure' => $this->section('erasure', $m, fn () => $this->erasure($m), null),
            'reports' => $this->section('reports', $m, fn () => $this->reports->detail($actor, $m), null),
            'can' => $this->can($actor, $m),
        ];

        if ($out['reports'] !== null) {
            $out['member']['reports'] = ['total' => $out['reports']['total'], 'open' => $out['reports']['open']];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function profile(User $m): array
    {
        $fields = [];
        if ($this->optional->masquerade()) {
            try {
                $rows = $this->db->table('fof_masquerade_fields as f')
                    ->leftJoin('fof_masquerade_answers as a', fn ($j) => $j->on('a.field_id', '=', 'f.id')->where('a.user_id', '=', $m->id))
                    ->orderBy('f.sort')
                    ->get(['f.name', 'f.icon', 'a.content']);
                foreach ($rows as $row) {
                    $fields[] = ['name' => $row->name, 'icon' => $row->icon, 'value' => $row->content];
                }
            } catch (\Throwable) {
                $fields = [];
            }
        }

        return [
            // fof/user-bio stores the bio as formatter XML (<t>…</t>, <r>…</r>): staff see its text.
            'bio' => ($bio = $m->getAttribute('bio')) ? trim(html_entity_decode(strip_tags((string) $bio), ENT_QUOTES | ENT_HTML5)) : null,
            'locale' => $m->getPreference('locale'),
            'discloseOnline' => (bool) $m->getPreference('discloseOnline'),
            'fields' => $fields,
            'masquerade' => $this->optional->masquerade(),
        ];
    }

    /** @return array<string, mixed> */
    protected function activity(User $actor, User $m): array
    {
        $since = Carbon::now()->startOfMonth()->subMonths(11);
        $dates = $this->db->table('posts')
            ->where('user_id', $m->id)->where('type', 'comment')->where('created_at', '>=', $since)
            ->pluck('created_at');

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[$since->copy()->addMonths($i)->format('Y-m')] = 0;
        }
        foreach ($dates as $d) {
            $key = Carbon::parse($d)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]++;
            }
        }

        // CommentPost::query() is NOT limited to comments: Flarum builds each row as its own type,
        // so a "renamed the discussion" event post came back too (and has no formatted content).
        $posts = CommentPost::query()->whereVisibleTo($actor)
            ->where('posts.user_id', $m->id)->where('posts.type', CommentPost::$type)
            ->with('discussion')
            ->orderByDesc('posts.created_at')->limit(8)->get();

        $discussions = Discussion::query()->whereVisibleTo($actor)
            ->where('discussions.user_id', $m->id)
            ->orderByDesc('discussions.created_at')->limit(5)->get();

        $likes = null;
        if ($this->optional->likes()) {
            $given = $this->db->table('post_likes')->where('user_id', $m->id)->count();
            $received = $this->db->table('post_likes')
                ->whereIn('post_id', fn ($q) => $q->select('id')->from('posts')->where('user_id', $m->id))
                ->count();
            $likes = ['given' => $given, 'received' => $received];
        }

        return [
            'months' => collect($months)->map(fn ($n, $k) => ['month' => $k, 'posts' => $n])->values()->all(),
            'posts' => $posts->map(fn (Post $p) => [
                'id' => $p->id,
                'number' => $p->number,
                'discussionId' => $p->discussion_id,
                'discussion' => $p->discussion?->title,
                'slug' => $p->discussion?->slug,
                'excerpt' => $this->excerpt($p),
                'createdAt' => Presenter::date($p->created_at),
            ])->all(),
            'discussions' => $discussions->map(fn (Discussion $d) => [
                'id' => $d->id,
                'title' => $d->title,
                'slug' => $d->slug,
                'replies' => max(0, (int) $d->comment_count - 1),
                'createdAt' => Presenter::date($d->created_at),
            ])->all(),
            'likes' => $likes,
        ];
    }

    /**
     * Plain text from the STORED post, without rendering it. Rendering runs every installed
     * extension's formatter code (uploads, mentions, custom BBCode), and some of it expects a
     * web request and throws without one (JoZ3, Discuss d/40368/5: the first admin's page 500'd).
     */
    protected function excerpt(Post $p): string
    {
        $xml = (string) $p->getRawOriginal('content');
        // Mentions store their source (@"Name"#p12); show them as the post does, @Name.
        $xml = preg_replace('#<(USER|POST)MENTION\b[^>]*?\bdisplayname="([^"]*)"[^>]*>.*?</\1MENTION>#s', '@$2', $xml) ?? $xml;
        // Imported posts may be stored as HTML or plain text, not formatter XML (<r> or <t>).
        $text = preg_match('#^<[rt][ >/]#', $xml) ? null : html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5);
        if ($text === null) {
            try {
                $text = \s9e\TextFormatter\Utils::removeFormatting($xml);
            } catch (\Throwable) {
                $text = strip_tags($xml);
            }
        }

        return mb_substr(trim(preg_replace('/\s+/', ' ', $text) ?? ''), 0, 140);
    }

    /** @return array<int, array<string, mixed>> */
    protected function sessions(User $actor, User $m): array
    {
        $showIps = $this->access->ips($actor);

        return $this->db->table('access_tokens')
            ->where('user_id', $m->id)
            ->orderByDesc('last_activity_at')->limit(10)
            ->get(['type', 'created_at', 'last_activity_at', 'last_ip_address', 'last_user_agent'])
            ->map(fn ($t) => [
                'type' => $t->type,
                'createdAt' => Presenter::date($t->created_at),
                'lastActivityAt' => Presenter::date($t->last_activity_at),
                'ip' => $showIps ? $t->last_ip_address : null,
                'agent' => $this->agent((string) $t->last_user_agent),
            ])->all();
    }

    protected function agent(string $ua): string
    {
        if ($ua === '') {
            return '';
        }
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Linux') => 'Linux',
            default => '',
        };

        return trim($browser.($os ? ' on '.$os : ''));
    }

    /**
     * Every IP the member used (sessions + posts), newest first, each with the other accounts
     * that used it. Three queries plus one to load those accounts.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function ips(User $m): array
    {
        $byIp = [];
        $tokens = $this->db->table('access_tokens')->where('user_id', $m->id)->whereNotNull('last_ip_address')
            ->get(['last_ip_address', 'created_at', 'last_activity_at']);
        foreach ($tokens as $t) {
            $ip = (string) $t->last_ip_address;
            $e = $byIp[$ip] ??= ['ip' => $ip, 'sources' => [], 'count' => 0, 'firstSeen' => null, 'lastSeen' => null];
            $e['sources']['sessions'] = true;
            $e['count']++;
            $e['firstSeen'] = $this->min($e['firstSeen'], $t->created_at);
            $e['lastSeen'] = $this->max($e['lastSeen'], $t->last_activity_at ?? $t->created_at);
            $byIp[$ip] = $e;
        }

        $posts = $this->db->table('posts')->where('user_id', $m->id)->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->selectRaw('ip_address, count(*) as n, min(created_at) as first_at, max(created_at) as last_at')
            ->orderByRaw('max(created_at) desc')->limit(100)->get();
        foreach ($posts as $p) {
            $ip = (string) $p->ip_address;
            $e = $byIp[$ip] ??= ['ip' => $ip, 'sources' => [], 'count' => 0, 'firstSeen' => null, 'lastSeen' => null];
            $e['sources']['posts'] = true;
            $e['count'] += (int) $p->n;
            $e['firstSeen'] = $this->min($e['firstSeen'], $p->first_at);
            $e['lastSeen'] = $this->max($e['lastSeen'], $p->last_at);
            $byIp[$ip] = $e;
        }

        if (! $byIp) {
            return [];
        }

        uasort($byIp, fn ($a, $b) => strcmp((string) $b['lastSeen'], (string) $a['lastSeen']));
        $byIp = array_slice($byIp, 0, 50, true);
        $list = array_keys($byIp);

        $others = [];
        $pairs = $this->db->table('access_tokens')->whereIn('last_ip_address', $list)->where('user_id', '<>', $m->id)
            ->distinct()->get(['last_ip_address as ip', 'user_id'])
            ->merge($this->db->table('posts')->whereIn('ip_address', $list)->whereNotNull('user_id')->where('user_id', '<>', $m->id)
                ->distinct()->limit(500)->get(['ip_address as ip', 'user_id']));
        foreach ($pairs as $pair) {
            $others[(string) $pair->ip][(int) $pair->user_id] = true;
        }

        $userIds = collect($others)->flatMap(fn ($ids) => array_keys($ids))->unique()->take(200)->all();
        $users = $userIds ? User::query()->whereIn('id', $userIds)->get()->keyBy('id') : collect();

        $banned = [];
        if ($this->optional->banIps()) {
            $banned = $this->db->table('banned_ips')->whereIn('address', $list)->pluck('address')->flip()->all();
        }

        $out = [];
        foreach ($byIp as $ip => $e) {
            $ip = (string) $ip;
            $out[] = [
                'ip' => $ip,
                'sources' => array_keys($e['sources']),
                'count' => $e['count'],
                'firstSeen' => Presenter::date($e['firstSeen']),
                'lastSeen' => Presenter::date($e['lastSeen']),
                'banned' => isset($banned[$ip]),
                'others' => collect(array_keys($others[$ip] ?? []))
                    ->map(fn ($id) => $users->get($id))->filter()
                    ->map(fn (User $u) => ['id' => $u->id, 'username' => $u->username, 'displayName' => $u->display_name, 'avatarUrl' => $u->avatar_url])
                    ->values()->take(20)->all(),
            ];
        }

        return $out;
    }

    protected function min(mixed $a, mixed $b): mixed
    {
        if (! $a) {
            return $b;
        }
        if (! $b) {
            return $a;
        }

        return (string) $a < (string) $b ? $a : $b;
    }

    protected function max(mixed $a, mixed $b): mixed
    {
        if (! $a) {
            return $b;
        }
        if (! $b) {
            return $a;
        }

        return (string) $a > (string) $b ? $a : $b;
    }

    /** @return array<string, mixed>|null */
    protected function notes(User $actor, User $m): ?array
    {
        if (! $this->optional->notes()) {
            return null;
        }
        if (! $actor->hasPermission('user.viewModeratorNotes')) {
            return ['allowed' => false, 'items' => []];
        }

        $rows = $this->db->table('users_notes')->where('user_id', $m->id)->orderByDesc('created_at')->limit(50)
            ->get(['id', 'note', 'added_by_user_id', 'created_at']);
        $ids = $rows->pluck('added_by_user_id')->filter()->unique()->all();
        $authors = $ids ? User::query()->whereIn('id', $ids)->get()->keyBy('id') : collect();

        return [
            'allowed' => true,
            'canCreate' => $actor->hasPermission('user.createModeratorNotes'),
            'items' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'note' => strip_tags((string) $r->note),
                'by' => ($a = $authors->get($r->added_by_user_id)) ? $a->display_name : null,
                'createdAt' => Presenter::date($r->created_at),
            ])->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function warnings(User $actor, User $m): ?array
    {
        if (! $this->optional->warnings()) {
            return null;
        }
        if (! $actor->hasPermission('user.viewWarnings')) {
            return ['allowed' => false, 'items' => []];
        }

        $rows = $this->db->table('warnings')->where('user_id', $m->id)->orderByDesc('created_at')->limit(50)
            ->get(['id', 'public_comment', 'strikes', 'created_user_id', 'hidden_at', 'created_at']);
        $ids = $rows->pluck('created_user_id')->filter()->unique()->all();
        $authors = $ids ? User::query()->whereIn('id', $ids)->get()->keyBy('id') : collect();

        return [
            'allowed' => true,
            'strikes' => (int) $rows->whereNull('hidden_at')->sum('strikes'),
            'items' => $rows->map(fn ($r) => [
                'id' => (int) $r->id,
                'comment' => mb_substr(strip_tags((string) $r->public_comment), 0, 300),
                'strikes' => (int) $r->strikes,
                'hidden' => (bool) $r->hidden_at,
                'by' => ($a = $authors->get($r->created_user_id)) ? $a->display_name : null,
                'createdAt' => Presenter::date($r->created_at),
            ])->all(),
        ];
    }

    /** @return array<string, mixed>|null */
    protected function suspension(User $m): ?array
    {
        if (! $this->optional->suspend()) {
            return null;
        }

        return [
            'until' => $this->presenter->suspendedUntil($m),
            'reason' => $m->getAttribute('suspend_reason'),
            'message' => $m->getAttribute('suspend_message'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function log(User $actor, User $m): array
    {
        return AuditEntry::query()->where('user_id', $m->id)->with('actor')
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (AuditEntry $e) => $this->logPresenter->present($e, $actor))->all();
    }

    /** @return array<string, mixed>|null */
    protected function erasure(User $m): ?array
    {
        if (! $this->optional->gdpr()) {
            return null;
        }

        $r = $this->db->table('gdpr_erasure')->where('user_id', $m->id)->first(['status', 'created_at', 'processed_mode']);

        return $r ? ['status' => $r->status, 'mode' => $r->processed_mode, 'createdAt' => Presenter::date($r->created_at)] : null;
    }

    /** @return array<string, bool> */
    protected function can(User $actor, User $m): array
    {
        $out = [];
        foreach (Access::ACTIONS as $action) {
            $out[$action] = $this->access->action($actor, $action) && $this->access->skipReason($actor, $m, $action) === null;
        }
        $out['banIp'] = $this->optional->banIps() && $this->access->ips($actor) && $actor->hasPermission('fof.ban-ips.banIP');
        $out['editAdmin'] = $actor->isAdmin();

        return $out;
    }
}
