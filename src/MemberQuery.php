<?php

namespace ErnestDefoe\MemberDesk;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Turns the list's filters into one users query. The same filters drive the table, "select all
 * matching", bulk actions and CSV export, so they can never disagree.
 *
 * 🚨 Gates: email search and sort need the email permission; IP search and the banned-IP filter
 * need the IP permission; last-seen filters and sort need user.viewLastSeenAt (or admin), since
 * members may hide when they were online. A filter the actor can't use is ignored, never
 * answered (an answer would leak).
 */
class MemberQuery
{
    public const SORTS = [
        'username' => 'username',
        'email' => 'email',
        'joinedAt' => 'joined_at',
        'lastSeenAt' => 'last_seen_at',
        'posts' => 'comment_count',
        'discussions' => 'discussion_count',
    ];

    public function __construct(
        protected Access $access,
        protected Optional $optional,
        protected Reports $reports,
        protected TrustRead $trust,
    ) {
    }

    /**
     * @param array<string, mixed> $f
     * @return Builder<User>
     */
    public function build(User $actor, array $f): Builder
    {
        $query = User::query();

        $q = trim((string) Arr::get($f, 'q', ''));
        if ($q !== '') {
            $this->search($query, $actor, $q);
        }

        $this->groups($query, $actor, (array) Arr::get($f, 'groups', []));
        $this->statuses($query, $actor, (array) Arr::get($f, 'status', []));
        $this->dates($query, $actor, $f);
        $this->reports->filter($query, $actor, $f);
        $this->trustLevels($query, $actor, (array) Arr::get($f, 'trust', []));
        $this->postCounts($query, Arr::get($f, 'minPosts'), Arr::get($f, 'maxPosts'));

        return $query;
    }

    /**
     * @param Builder<User> $query
     * @param array<int|string, mixed> $groups
     */
    protected function groups(Builder $query, User $actor, array $groups): void
    {
        $groups = array_filter($groups, fn ($g) => $g !== '' && $g !== null);
        if (! $groups) {
            return;
        }
        $ids = array_map('intval', array_filter($groups, 'is_numeric'));
        // 🚨 Filtering by a hidden group would list its members: only for staff who may see it.
        if ($ids && ! $actor->can('viewHiddenGroups')) {
            $ids = \Flarum\Group\Group::query()->whereIn('id', $ids)->where('is_hidden', false)->pluck('id')->map(fn ($id) => (int) $id)->all() ?: [0];
        }
        $none = in_array('none', $groups, true);
        $query->where(function (Builder $w) use ($ids, $none) {
            if ($ids) {
                $w->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $ids));
            }
            if ($none) {
                // Some forums hold explicit Member rows: those still count as "no extra group".
                $w->orWhereDoesntHave('groups', fn ($g) => $g->whereNotIn('groups.id', [\Flarum\Group\Group::GUEST_ID, \Flarum\Group\Group::MEMBER_ID]));
            }
        });
    }

    /**
     * @param Builder<User> $query
     * @param array<int|string, mixed> $statuses
     */
    protected function statuses(Builder $query, User $actor, array $statuses): void
    {
        foreach ($statuses as $status) {
            match ($status) {
                'unconfirmed' => $query->where('is_email_confirmed', false),
                'confirmed' => $query->where('is_email_confirmed', true),
                'noposts' => $query->where('comment_count', 0),
                'suspended' => $this->optional->suspend() ? $query->where('suspended_until', '>', Carbon::now()) : null,
                'anonymized' => $this->optional->gdpr() ? $query->where('anonymized', true) : null,
                'banned' => $this->optional->banIps() && $this->access->ips($actor) ? $this->banned($query) : null,
                'silenced' => $this->access->trust($actor) ? $this->trust->whereSilenced($query) : null,
                default => null,
            };
        }
    }

    /**
     * Joined and last-seen ranges; last seen only for staff who may see it.
     *
     * @param Builder<User> $query
     * @param array<string, mixed> $f
     */
    protected function dates(Builder $query, User $actor, array $f): void
    {
        $this->dateRange($query, 'joined_at', Arr::get($f, 'joinedFrom'), Arr::get($f, 'joinedTo'));
        if ($within = $this->interval(Arr::get($f, 'joinedWithin'))) {
            $query->where('joined_at', '>=', Carbon::now()->sub($within));
        }

        if ($this->access->lastSeen($actor)) {
            $this->dateRange($query, 'last_seen_at', Arr::get($f, 'seenFrom'), Arr::get($f, 'seenTo'));
            if ($away = $this->interval(Arr::get($f, 'notSeenFor'))) {
                $cut = Carbon::now()->sub($away);
                $query->where(fn (Builder $w) => $w->where('last_seen_at', '<', $cut)->orWhereNull('last_seen_at'));
            }
        }
    }

    /**
     * Trust levels 0–4, only for staff Trust Levels lets see them.
     *
     * @param Builder<User> $query
     * @param array<int|string, mixed> $trust
     */
    protected function trustLevels(Builder $query, User $actor, array $trust): void
    {
        $levels = array_values(array_unique(array_filter(array_map('intval', array_filter($trust, 'is_numeric')), fn ($l) => $l >= 0 && $l <= 4)));
        if ($levels && $this->access->trust($actor)) {
            $this->trust->whereLevel($query, $levels);
        }
    }

    /** @param Builder<User> $query */
    protected function postCounts(Builder $query, mixed $min, mixed $max): void
    {
        if (is_numeric($min)) {
            $query->where('comment_count', '>=', (int) $min);
        }
        if (is_numeric($max)) {
            $query->where('comment_count', '<=', (int) $max);
        }
    }

    /** @param Builder<User> $query */
    public function sort(Builder $query, User $actor, ?string $sort, ?string $dir): void
    {
        $column = self::SORTS[$sort ?? ''] ?? 'joined_at';
        if ($column === 'email' && ! $this->access->emails($actor)) {
            $column = 'joined_at';
        }
        if ($column === 'last_seen_at' && ! $this->access->lastSeen($actor)) {
            $column = 'joined_at';
        }

        $direction = $dir === 'asc' ? 'asc' : 'desc';
        $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * Names and emails match from their start, like Flarum's own user search: a prefix uses the
     * username and email indexes, where "contains" would scan every member on each keystroke.
     *
     * @param Builder<User> $query
     */
    protected function search(Builder $query, User $actor, string $q): void
    {
        $prefix = addcslashes($q, '%_\\').'%';
        $looksLikeIp = (bool) preg_match('/^[0-9a-f.:]+$/i', $q) && (str_contains($q, '.') || str_contains($q, ':'));
        $ips = $looksLikeIp && $this->access->ips($actor);
        $emails = $this->access->emails($actor);
        $nickname = $this->optional->nicknames();

        $query->where(function (Builder $w) use ($prefix, $q, $ips, $emails, $nickname) {
            $w->where('username', 'like', $prefix);
            if ($nickname) {
                $w->orWhere('nickname', 'like', $prefix);
            }
            if ($emails) {
                $w->orWhere('email', 'like', $prefix);
            }
            if ($ips) {
                $this->searchIps($w, $q, $prefix);
            }
        });
    }

    /** @param Builder<User> $w */
    protected function searchIps(Builder $w, string $q, string $prefix): void
    {
        $whole = (bool) filter_var($q, FILTER_VALIDATE_IP);
        $w->orWhereIn('id', fn ($s) => $s->select('user_id')->from('access_tokens')
            ->where('last_ip_address', $whole ? '=' : 'like', $whole ? $q : $prefix));
        // Posts' IP column isn't indexed, so it's matched only on a whole address.
        if ($whole) {
            $w->orWhereIn('id', fn ($s) => $s->select('user_id')->from('posts')->where('ip_address', $q)->whereNotNull('user_id'));
        }
    }

    /** @param Builder<User> $query */
    protected function banned(Builder $query): void
    {
        $query->where(function (Builder $w) {
            $w->whereIn('id', fn ($s) => $s->select('user_id')->from('banned_ips')->whereNotNull('user_id'))
                ->orWhereIn('id', fn ($s) => $s->select('user_id')->from('access_tokens')
                    ->whereIn('last_ip_address', fn ($b) => $b->select('address')->from('banned_ips')));
        });
    }

    /** @param Builder<User> $query */
    protected function dateRange(Builder $query, string $column, mixed $from, mixed $to): void
    {
        if (is_string($from) && ($d = $this->date($from))) {
            $query->where($column, '>=', $d->startOfDay());
        }
        if (is_string($to) && ($d = $this->date($to))) {
            $query->where($column, '<=', $d->endOfDay());
        }
    }

    protected function date(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function interval(mixed $value): ?CarbonInterval
    {
        if (! is_string($value) || ! preg_match('/^P\d+[DWMY]$/', $value)) {
            return null;
        }

        try {
            return new CarbonInterval($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
