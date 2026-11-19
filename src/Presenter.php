<?php

namespace ErnestDefoe\MemberDesk;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Shapes members for the list and the member page.
 *
 * The list is a FIXED number of queries whatever the page size (tested at 1 and 50 rows): the page
 * itself, its groups (one eager load), last IPs (one query on access_tokens) and banned-IP flags
 * (one query, fof/ban-ips). Suspension, confirmation and anonymized flags are columns on users.
 */
class Presenter
{
    public function __construct(
        protected Access $access,
        protected Optional $optional,
        protected ConnectionInterface $db,
        protected TrustRead $trust,
    ) {
    }

    /**
     * @param Collection<int, User> $members groups already eager-loaded
     * @return array<int, array<string, mixed>>
     */
    public function rows(User $actor, Collection $members): array
    {
        $ids = $members->pluck('id')->all();
        $showIps = $this->access->ips($actor);
        $lastIps = $showIps && $ids ? $this->lastIps($ids) : [];
        $banned = $showIps && $ids && $this->optional->banIps() ? $this->bannedFlags($ids, $lastIps) : [];
        // One query for every row's trust state (Trust Levels' own table), never one per member.
        if ($members instanceof EloquentCollection && $this->access->trust($actor) && ($eager = $this->trust->eager())) {
            $members->loadMissing($eager);
        }

        return $members->map(fn (User $m) => $this->row($actor, $m, $lastIps[$m->id] ?? null, isset($banned[$m->id])))->values()->all();
    }

    /** @return array<string, mixed> */
    public function row(User $actor, User $m, ?string $lastIp = null, bool $banned = false): array
    {
        return [
            'id' => $m->id,
            'username' => $m->username,
            'displayName' => $m->display_name,
            'avatarUrl' => $m->avatar_url,
            'email' => $this->email($actor, $m),
            'confirmed' => (bool) $m->is_email_confirmed,
            // Some forums hold an explicit Member (3) row; Guest and Member aren't groups anyone picks.
            // Hidden groups only for staff who may see them.
            'groups' => $m->groups
                ->reject(fn ($g) => (int) $g->id === \Flarum\Group\Group::GUEST_ID || (int) $g->id === \Flarum\Group\Group::MEMBER_ID
                    || ($g->is_hidden && ! $this->hiddenGroups($actor)))
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'joinedAt' => self::date($m->joined_at),
            'lastSeenAt' => $this->lastSeen($actor, $m),
            'posts' => (int) $m->comment_count,
            'discussions' => (int) $m->discussion_count,
            'lastIp' => $lastIp,
            'suspendedUntil' => $this->optional->suspend() ? $this->suspendedUntil($m) : null,
            'anonymized' => $this->optional->gdpr() && (bool) $m->getAttribute('anonymized'),
            'bannedIp' => $banned,
            'isAdmin' => $this->access->isAdmin($m),
            'isYou' => $m->id === $actor->id,
            'trust' => $this->access->trust($actor) ? $this->trust->of($m) : null,
            // From Reports::addCounts() on the list (null where reports aren't shown to this viewer).
            'reports' => $m->getAttribute('md_reports_total') === null ? null
                : ['total' => (int) $m->getAttribute('md_reports_total'), 'open' => (int) $m->getAttribute('md_reports_open')],
        ];
    }

    /**
     * A date as ISO 8601, or null when it is unknown. Imported forums (Vanilla through
     * Nitro-Porter, for one) carry zero dates like 0000-00-00 and unparsable strings: those
     * read as "unknown", never as the year -1 or an error.
     */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = $value instanceof \DateTimeInterface ? \Carbon\Carbon::instance($value) : \Carbon\Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return $date->year < 1971 ? null : $date->toIso8601String();
    }

    /** @var array<int, bool> */
    protected array $hiddenMemo = [];

    protected function hiddenGroups(User $actor): bool
    {
        return $this->hiddenMemo[$actor->id] ??= $actor->can('viewHiddenGroups');
    }

    public function email(User $actor, User $m): string
    {
        $email = (string) $m->email;
        if ($this->access->emails($actor)) {
            return $email;
        }

        // j•••@example.com: enough for a moderator to tell two accounts apart, not to contact them.
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    public function lastSeen(User $actor, User $m): ?string
    {
        if (! $m->last_seen_at) {
            return null;
        }
        if ($this->access->lastSeen($actor) || $m->getPreference('discloseOnline')) {
            return self::date($m->last_seen_at);
        }

        return null;
    }

    public function suspendedUntil(User $m): ?string
    {
        $until = $m->getAttribute('suspended_until');
        if (! $until) {
            return null;
        }
        $until = $until instanceof Carbon ? $until : Carbon::parse($until);

        return $until->isFuture() ? $until->toIso8601String() : null;
    }

    /**
     * @param int[] $ids
     * @return array<int, string>
     */
    public function lastIps(array $ids): array
    {
        $out = [];
        $rows = $this->db->table('access_tokens')
            ->whereIn('user_id', $ids)
            ->whereNotNull('last_ip_address')
            ->orderBy('last_activity_at', 'desc')
            ->get(['user_id', 'last_ip_address']);

        foreach ($rows as $row) {
            $out[(int) $row->user_id] ??= (string) $row->last_ip_address;
        }

        return $out;
    }

    /**
     * @param int[] $ids
     * @param array<int, string> $last each member's last IP
     * @return array<int, true>
     */
    protected function bannedFlags(array $ids, array $last): array
    {
        $ips = array_values(array_unique($last));
        $rows = $this->db->table('banned_ips')
            ->where(fn ($w) => $w->whereIn('user_id', $ids)->orWhereIn('address', $ips ?: ['']))
            ->get(['user_id', 'address']);

        $byIp = [];
        $out = [];
        foreach ($rows as $row) {
            if ($row->user_id) {
                $out[(int) $row->user_id] = true;
            }
            $byIp[(string) $row->address] = true;
        }

        foreach ($ids as $id) {
            if (isset($last[$id], $byIp[$last[$id]])) {
                $out[$id] = true;
            }
        }

        return $out;
    }
}
