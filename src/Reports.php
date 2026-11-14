<?php

namespace ErnestDefoe\MemberDesk;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reports about a member, from Ernest's Docket (ernestdefoe/docket) when it's on, else plain
 * flarum/flags counts, else nothing (the column, filters and tab are hidden).
 *
 * 🚨 Always through the owner's own visibility rules (Docket's Report/Entry scopes, flags' Flag
 * scope), so a moderator sees only the reports Docket or Flags would show them, in the column,
 * the filters (no enumerating members by a report they can't see) and the member page.
 *
 * The list costs no extra round trip: the counts are two correlated subselects on the page query.
 */
class Reports
{
    public function __construct(protected Optional $optional)
    {
    }

    /** 'docket', 'flags' or null for this viewer. */
    public function source(User $actor): ?string
    {
        if (! $actor->hasPermissionLike('discussion.viewFlags')) {
            return null;
        }
        if ($this->optional->docket()) {
            return 'docket';
        }

        return $this->optional->flags() ? 'flags' : null;
    }

    /**
     * Visible reports against members: Docket's reports, or flags' flags.
     *
     * @return Builder<\ErnestDefoe\Docket\Report>|Builder<\Flarum\Flags\Flag>
     */
    protected function against(User $actor, string $source, bool $openOnly = false): Builder
    {
        if ($source === 'docket') {
            $q = \ErnestDefoe\Docket\Report::query()->whereVisibleTo($actor);
            if ($openOnly) {
                $q->whereIn('docket_reports.status', \ErnestDefoe\Docket\Report::ACTIVE);
            }

            return $q;
        }

        // flarum/flags: a flag exists until it is dismissed, so every visible flag is "open".
        return \Flarum\Flags\Flag::query()->whereVisibleTo($actor);
    }

    /** Adds md_reports_total and md_reports_open to a users query (no extra query). */
    public function addCounts(Builder $users, User $actor): void
    {
        $source = $this->source($actor);
        if (! $source) {
            return;
        }

        $table = $users->getModel()->getTable();
        if (empty($users->getQuery()->columns)) {
            $users->select("$table.*");
        }

        $users->addSelect([
            'md_reports_total' => $this->countFor($actor, $source, false, $table),
            'md_reports_open' => $this->countFor($actor, $source, true, $table),
        ]);
    }

    /** @return Builder<\ErnestDefoe\Docket\Report>|Builder<\Flarum\Flags\Flag> */
    protected function countFor(User $actor, string $source, bool $openOnly, string $users): Builder
    {
        $q = $this->against($actor, $source, $openOnly)->selectRaw('count(*)');

        return $source === 'docket'
            ? $q->whereColumn('docket_reports.user_id', "$users.id")
            : $q->whereIn('flags.post_id', fn ($p) => $p->select('id')->from('posts')->whereColumn('posts.user_id', "$users.id"));
    }

    /**
     * The list's report filters: `status[]=reported` (has open reports) and
     * `reportedMin=N&reportedWithin=P30D` (reported N+ times in that period).
     *
     * @param Builder<User> $users
     * @param array<string, mixed> $f
     */
    public function filter(Builder $users, User $actor, array $f): void
    {
        $source = $this->source($actor);
        if (! $source) {
            return;
        }

        if (in_array('reported', (array) ($f['status'] ?? []), true)) {
            $users->whereIn('id', $this->reportedUserIds($actor, $source, true));
        }

        $min = $f['reportedMin'] ?? null;
        if (is_numeric($min) && (int) $min > 0) {
            $within = $this->interval($f['reportedWithin'] ?? null);
            $users->whereIn('id', $this->reportedUserIds($actor, $source, false, (int) $min, $within ? Carbon::now()->sub($within) : null));
        }
    }

    /** @return \Illuminate\Database\Query\Builder */
    protected function reportedUserIds(User $actor, string $source, bool $openOnly, int $min = 1, ?Carbon $since = null)
    {
        $q = $this->against($actor, $source, $openOnly);

        if ($source === 'docket') {
            $q->select('docket_reports.user_id')->whereNotNull('docket_reports.user_id');
            if ($since) {
                $q->where('docket_reports.created_at', '>=', $since);
            }
            $q->groupBy('docket_reports.user_id');
        } else {
            $q->join('posts', 'posts.id', '=', 'flags.post_id')->select('posts.user_id')->whereNotNull('posts.user_id');
            if ($since) {
                $q->where('flags.created_at', '>=', $since);
            }
            $q->groupBy('posts.user_id');
        }

        if ($min > 1) {
            $q->havingRaw('count(*) >= ?', [$min]);
        }

        return $q->toBase();
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

    /**
     * The member page's Reports tab: reports against them and reports they filed.
     *
     * @return array<string, mixed>|null
     */
    public function detail(User $actor, User $m): ?array
    {
        $source = $this->source($actor);
        if (! $source) {
            return null;
        }

        return $source === 'docket' ? $this->docketDetail($actor, $m) : $this->flagsDetail($actor, $m);
    }

    /** @return array<string, mixed> */
    protected function docketDetail(User $actor, User $m): array
    {
        $against = \ErnestDefoe\Docket\Report::query()->whereVisibleTo($actor)
            ->where('docket_reports.user_id', $m->id)
            ->with(['assignee', 'post.discussion', 'discussion'])
            ->orderByDesc('docket_reports.id')->limit(25)->get();

        $counts = \ErnestDefoe\Docket\Report::query()->whereVisibleTo($actor)->where('docket_reports.user_id', $m->id);
        // 🚨 Raw SQL gets no table prefix: wrap the column through the grammar.
        $status = $counts->getQuery()->getGrammar()->wrap('docket_reports.status');
        $counts = $counts->selectRaw("count(*) as total, coalesce(sum(case when $status in (?, ?) then 1 else 0 end), 0) as open", \ErnestDefoe\Docket\Report::ACTIVE)
            ->toBase()->first();

        $filed = \ErnestDefoe\Docket\Entry::query()->whereVisibleTo($actor)
            ->where('docket_entries.user_id', $m->id)
            ->with(['report.user', 'report.post.discussion'])
            ->orderByDesc('docket_entries.id')->limit(25)->get();

        return [
            'source' => 'docket',
            'total' => (int) ($counts->total ?? 0),
            'open' => (int) ($counts->open ?? 0),
            'against' => $against->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'reports' => (int) $r->entry_count,
                'assignee' => $r->assignee?->display_name,
                'outcome' => $r->outcome ? mb_substr(trim(strip_tags((string) $r->outcome)), 0, 300) : null,
                'discussion' => $r->post->discussion->title ?? $r->discussion?->title,
                'createdAt' => $r->created_at?->toIso8601String(),
                'closedAt' => $r->closed_at?->toIso8601String(),
            ])->all(),
            'filed' => $filed->map(fn ($e) => [
                'reportId' => $e->report_id,
                'status' => $e->report?->status,
                'reason' => $e->reason,
                'about' => $e->report?->user?->display_name,
                'discussion' => $e->report?->post?->discussion?->title,
                'createdAt' => $e->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function flagsDetail(User $actor, User $m): array
    {
        $visible = fn () => \Flarum\Flags\Flag::query()->whereVisibleTo($actor);

        $against = $visible()->whereIn('flags.post_id', fn ($p) => $p->select('id')->from('posts')->where('user_id', $m->id))
            ->with(['post.discussion'])->orderByDesc('flags.id')->limit(25)->get();
        $total = $visible()->whereIn('flags.post_id', fn ($p) => $p->select('id')->from('posts')->where('user_id', $m->id))->count();
        $filed = $visible()->where('flags.user_id', $m->id)->with(['post.discussion', 'post.user'])->orderByDesc('flags.id')->limit(25)->get();

        return [
            'source' => 'flags',
            'total' => $total,
            'open' => $total,
            'against' => $against->map(fn ($f) => [
                'id' => null,
                'postId' => $f->post_id,
                'number' => $f->post?->number,
                'discussionId' => $f->post?->discussion_id,
                'slug' => $f->post?->discussion?->slug,
                'status' => 'open',
                'reason' => $f->reason ?: $f->type,
                'discussion' => $f->post?->discussion?->title,
                'createdAt' => $f->created_at?->toIso8601String(),
            ])->all(),
            'filed' => $filed->map(fn ($f) => [
                'reportId' => null,
                'postId' => $f->post_id,
                'number' => $f->post?->number,
                'discussionId' => $f->post?->discussion_id,
                'slug' => $f->post?->discussion?->slug,
                'status' => 'open',
                'reason' => $f->reason ?: $f->type,
                'about' => $f->post?->user?->display_name,
                'discussion' => $f->post?->discussion?->title,
                'createdAt' => $f->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
