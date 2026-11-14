<?php

namespace ErnestDefoe\MemberDesk\Api;

use Carbon\Carbon;
use ErnestDefoe\MemberDesk\Access;
use ErnestDefoe\MemberDesk\Audit\Recorder;
use ErnestDefoe\MemberDesk\MemberQuery;
use ErnestDefoe\MemberDesk\Optional;
use ErnestDefoe\MemberDesk\Presenter;
use Flarum\User\User;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;

/**
 * CSV of the current filter (or the selected ids), written 500 rows at a time to a temp stream
 * so a large forum never holds every member in memory. Cells that a spreadsheet would run as a
 * formula (= + - @) are quoted with a leading apostrophe. Every export is logged.
 */
class Export
{
    public function __construct(
        protected MemberQuery $query,
        protected Presenter $presenter,
        protected Access $access,
        protected Optional $optional,
        protected Recorder $recorder,
    ) {
    }

    /** @param array<string, mixed> $params */
    public function respond(User $actor, array $params): ResponseInterface
    {
        $ids = array_filter(array_map('intval', explode(',', (string) ($params['ids'] ?? ''))));
        $query = $ids ? User::query()->whereIn('id', $ids) : $this->query->build($actor, $params);
        $this->query->sort($query, $actor, $params['sort'] ?? null, $params['dir'] ?? null);

        $ips = $this->access->ips($actor);
        $suspend = $this->optional->suspend();
        $gdpr = $this->optional->gdpr();

        $out = fopen('php://temp', 'w+');
        $head = ['ID', 'Username', 'Display name', 'Email', 'Email verified', 'Groups', 'Joined', 'Last seen', 'Posts', 'Discussions'];
        if ($ips) {
            $head[] = 'Last IP';
        }
        if ($suspend) {
            $head[] = 'Suspended until';
        }
        if ($gdpr) {
            $head[] = 'Anonymized';
        }
        fputcsv($out, $head, ',', '"', '');

        $count = 0;
        $query->with('groups')->chunk(500, function ($members) use ($out, $actor, $ips, $suspend, $gdpr, &$count) {
            $lastIps = $ips ? $this->presenter->lastIps($members->pluck('id')->all()) : [];
            foreach ($members as $m) {
                /** @var User $m */
                $row = [
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
                ];
                if ($ips) {
                    $row[] = $lastIps[$m->id] ?? '';
                }
                if ($suspend) {
                    $row[] = $this->presenter->suspendedUntil($m) ?? '';
                }
                if ($gdpr) {
                    $row[] = $m->getAttribute('anonymized') ? 'yes' : 'no';
                }
                fputcsv($out, array_map([$this, 'safe'], $row), ',', '"', '');
                $count++;
            }
        });

        $this->recorder->record($actor, 'export', null, null, ['rows' => $count, 'filters' => $ids ? ['ids' => count($ids)] : array_intersect_key($params, array_flip(['q', 'groups', 'status', 'joinedFrom', 'joinedTo', 'seenFrom', 'seenTo', 'joinedWithin', 'notSeenFor', 'minPosts', 'maxPosts']))]);

        rewind($out);
        $name = 'members-'.Carbon::now()->format('Y-m-d').'.csv';

        return (new Response(new Stream($out), 200))
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="'.$name.'"')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function safe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
