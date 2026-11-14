<?php

namespace ErnestDefoe\MemberDesk;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
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
        protected ConnectionInterface $db,
        protected Reports $reports,
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

        $groups = array_filter((array) Arr::get($f, 'groups', []), fn ($g) => $g !== '' && $g !== null);
        if ($groups) {
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

        foreach ((array) Arr::get($f, 'status', []) as $status) {
            match ($status) {
                'unconfirmed' => $query->where('is_email_confirmed', false),
                'confirmed' => $query->where('is_email_confirmed', true),
                'noposts' => $query->where('comment_count', 0),
                'suspended' => $this->optional->suspend() ? $query->where('suspended_until', '>', Carbon::now()) : null,
                'anonymized' => $this->optional->gdpr() ? $query->where('anonymized', true) : null,
                'banned' => $this->optional->banIps() && $this->access->ips($actor) ? $this->banned($query) : null,
                'silenced' => $this->access->trust($actor) ? $query->whereNotNull('trust_silenced_at') : null,
                default => null,
            };
        }

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

        $this->reports->filter($query, $actor, $f);

        // Trust levels 0–4, only for staff Trust Levels lets see them.
        $levels = array_values(array_unique(array_filter(array_map('intval', array_filter((array) Arr::get($f, 'trust', []), 'is_numeric')), fn ($l) => $l >= 0 && $l <= 4)));
        if ($levels && $this->access->trust($actor)) {
            $query->whereIn('trust_level', $levels);
        }

        $min = Arr::get($f, 'minPosts');
        if (is_numeric($min)) {
            $query->where('comment_count', '>=', (int) $min);
        }
        $max = Arr::get($f, 'maxPosts');
        if (is_numeric($max)) {
            $query->where('comment_count', '<=', (int) $max);
        }

        return $query;
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

    /** @param Builder<User> $query */
    protected function search(Builder $query, User $actor, string $q): void
    {
        $like = '%'.addcslashes($q, '%_\\').'%';
        $prefix = addcslashes($q, '%_\\').'%';
        $looksLikeIp = (bool) preg_match('/^[0-9a-f.:]+$/i', $q) && (str_contains($q, '.') || str_contains($q, ':'));
        $ips = $looksLikeIp && $this->access->ips($actor);
        $emails = $this->access->emails($actor);
        $nickname = $this->hasNickname();

        $query->where(function (Builder $w) use ($like, $prefix, $q, $ips, $emails, $nickname) {
            $w->where('username', 'like', $like);
            if ($nickname) {
                $w->orWhere('nickname', 'like', $like);
            }
            if ($emails) {
                $w->orWhere('email', 'like', $like);
            }
            if ($ips) {
                $whole = (bool) filter_var($q, FILTER_VALIDATE_IP);
                $w->orWhereIn('id', fn ($s) => $s->select('user_id')->from('access_tokens')
                    ->where('last_ip_address', $whole ? '=' : 'like', $whole ? $q : $prefix));
                // Posts' IP column isn't indexed, so it's matched only on a whole address.
                if (filter_var($q, FILTER_VALIDATE_IP)) {
                    $w->orWhereIn('id', fn ($s) => $s->select('user_id')->from('posts')->where('ip_address', $q)->whereNotNull('user_id'));
                }
            }
        });
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

    protected ?bool $nickname = null;

    protected function hasNickname(): bool
    {
        return $this->nickname ??= $this->db->getSchemaBuilder()->hasColumn('users', 'nickname');
    }
}
