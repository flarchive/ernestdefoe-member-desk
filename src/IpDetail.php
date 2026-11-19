<?php

namespace ErnestDefoe\MemberDesk;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * The member page's IP section: every IP the member used, and the other accounts seen on it.
 * Only read for staff holding Member Desk's IP permission (MemberDetail checks it).
 */
class IpDetail
{
    public function __construct(
        protected Optional $optional,
        protected ConnectionInterface $db,
    ) {
    }

    /**
     * Every IP the member used (sessions + posts), newest first, each with the other accounts
     * that used it. Three queries plus one to load those accounts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ips(User $m): array
    {
        $byIp = $this->ipUses($m);
        if (! $byIp) {
            return [];
        }

        uasort($byIp, fn ($a, $b) => strcmp((string) $b['lastSeen'], (string) $a['lastSeen']));
        $byIp = array_slice($byIp, 0, 50, true);
        $list = array_map('strval', array_keys($byIp));
        [$others, $users] = $this->sharedWith($m, $list);
        $banned = $this->optional->banIps() ? $this->db->table('banned_ips')->whereIn('address', $list)->pluck('address')->flip()->all() : [];

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

    /**
     * The member's IPs from sessions and posts (latest 100 post IPs), with counts and dates.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function ipUses(User $m): array
    {
        $byIp = [];
        $add = function (string $ip, string $source, int $n, mixed $first, mixed $last) use (&$byIp) {
            $e = $byIp[$ip] ?? ['ip' => $ip, 'sources' => [], 'count' => 0, 'firstSeen' => null, 'lastSeen' => null];
            $e['sources'][$source] = true;
            $e['count'] += $n;
            $e['firstSeen'] = $this->min($e['firstSeen'], $first);
            $e['lastSeen'] = $this->max($e['lastSeen'], $last);
            $byIp[$ip] = $e;
        };

        $tokens = $this->db->table('access_tokens')->where('user_id', $m->id)->whereNotNull('last_ip_address')
            ->get(['last_ip_address', 'created_at', 'last_activity_at']);
        foreach ($tokens as $t) {
            $add((string) $t->last_ip_address, 'sessions', 1, $t->created_at, $t->last_activity_at ?? $t->created_at);
        }

        // Identifiers quoted by the grammar, so the raw parts hold on every driver.
        $grammar = $this->db->getQueryGrammar();
        [$ip, $at] = [$grammar->wrap('ip_address'), $grammar->wrap('created_at')];
        $posts = $this->db->table('posts')->where('user_id', $m->id)->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->selectRaw("$ip, count(*) as n, min($at) as first_at, max($at) as last_at")
            ->orderByRaw("max($at) desc")->limit(100)->get();
        foreach ($posts as $p) {
            $add((string) $p->ip_address, 'posts', (int) $p->n, $p->first_at, $p->last_at);
        }

        return $byIp;
    }

    /**
     * Other accounts seen on these IPs: [ip => [userId => true]], and those users (up to 200).
     *
     * @param string[] $list
     * @return array{0: array<string, array<int, bool>>, 1: \Illuminate\Support\Collection<int, User>}
     */
    protected function sharedWith(User $m, array $list): array
    {
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

        return [$others, $users];
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
}
