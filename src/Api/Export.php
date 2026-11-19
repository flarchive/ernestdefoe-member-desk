<?php

namespace ErnestDefoe\MemberDesk\Api;

use Carbon\Carbon;
use ErnestDefoe\MemberDesk\Access;
use ErnestDefoe\MemberDesk\Audit\Recorder;
use ErnestDefoe\MemberDesk\MemberQuery;
use ErnestDefoe\MemberDesk\Optional;
use ErnestDefoe\MemberDesk\Presenter;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CSV of the current filter (or the selected ids), written 500 rows at a time to a temp stream
 * so a large forum never holds every member in memory. Cells that a spreadsheet would run as a
 * formula (= + - @) are quoted with a leading apostrophe. Every export is logged, once: some
 * browsers and download helpers fetch an attachment twice (Claudius, ernestdefoe.online d/267/4),
 * so a repeat of the same request by the same person within a minute is served but not logged
 * again. The Export button adds a one-time `dl` value, so a second real click still logs.
 */
class Export
{
    public function __construct(
        protected MemberQuery $query,
        protected Presenter $presenter,
        protected Access $access,
        protected Optional $optional,
        protected Recorder $recorder,
        protected Cache $cache,
        protected TranslatorInterface $translator,
    ) {
    }

    /** @param array<string, mixed> $params */
    public function respond(User $actor, array $params): ResponseInterface
    {
        $ids = array_filter(array_map('intval', explode(',', (string) ($params['ids'] ?? ''))));
        $query = $ids ? User::query()->whereIn('id', $ids) : $this->query->build($actor, $params);
        $this->query->sort($query, $actor, $params['sort'] ?? null, $params['dir'] ?? null);
        // Nothing matches: say so instead of sending a CSV that is only a header line (Claudius, d/267/10).
        if (! (clone $query)->exists()) {
            throw new ValidationException(['export' => $this->translator->trans('ernestdefoe-member-desk.lib.list.nothing_to_export')]);
        }

        $cols = ['ips' => $this->access->ips($actor), 'suspend' => $this->optional->suspend(), 'gdpr' => $this->optional->gdpr()];
        $out = fopen('php://temp', 'w+');
        fputcsv($out, $this->head($cols), ',', '"', '');

        $count = 0;
        $query->with('groups')->chunk(500, function ($members) use ($out, $actor, $cols, &$count) {
            $lastIps = $cols['ips'] ? $this->presenter->lastIps($members->pluck('id')->all()) : [];
            foreach ($members as $member) {
                /** @var User $member */
                fputcsv($out, array_map([$this, 'safe'], $this->row($actor, $member, $cols, $lastIps)), ',', '"', '');
                $count++;
            }
        });

        $this->logOnce($actor, $params, $ids, $count);

        rewind($out);
        $name = 'members-'.Carbon::now()->format('Y-m-d').'.csv';

        return (new Response(new Stream($out), 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="'.$name.'"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, bool> $cols
     * @return string[]
     */
    protected function head(array $cols): array
    {
        return array_merge(
            ['ID', 'Username', 'Display name', 'Email', 'Email verified', 'Groups', 'Joined', 'Last seen', 'Posts', 'Discussions'],
            $cols['ips'] ? ['Last IP'] : [],
            $cols['suspend'] ? ['Suspended until'] : [],
            $cols['gdpr'] ? ['Anonymized'] : [],
        );
    }

    /**
     * @param array<string, bool> $cols
     * @param array<int, string> $lastIps
     * @return array<int, mixed>
     */
    protected function row(User $actor, User $m, array $cols, array $lastIps): array
    {
        return array_merge(
            [
                $m->id,
                $m->username,
                $m->display_name,
                $m->email,
                $m->is_email_confirmed ? 'yes' : 'no',
                $m->groups->pluck('name_singular')->implode('; '),
                $m->joined_at?->toDateTimeString(),
                $this->presenter->lastSeen($actor, $m) ? $m->last_seen_at?->toDateTimeString() : '',
                (int) $m->comment_count,
                (int) $m->discussion_count,
            ],
            $cols['ips'] ? [$lastIps[$m->id] ?? ''] : [],
            $cols['suspend'] ? [$this->presenter->suspendedUntil($m) ?? ''] : [],
            $cols['gdpr'] ? [$m->getAttribute('anonymized') ? 'yes' : 'no'] : [],
        );
    }

    /**
     * @param array<string, mixed> $params
     * @param int[] $ids
     */
    protected function logOnce(User $actor, array $params, array $ids, int $count): void
    {
        ksort($params);
        $once = 'member-desk.export.'.$actor->id.'.'.sha1((string) json_encode($params));
        if ($this->cache->add($once, 1, 60)) {
            $this->recorder->record($actor, 'export', null, null, ['rows' => $count, 'filters' => $ids ? ['ids' => count($ids)] : array_intersect_key($params, array_flip(['q', 'groups', 'status', 'joinedFrom', 'joinedTo', 'seenFrom', 'seenTo', 'joinedWithin', 'notSeenFor', 'minPosts', 'maxPosts']))]);
        }
    }

    public function safe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
