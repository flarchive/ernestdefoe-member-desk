<?php

namespace ErnestDefoe\MemberDesk\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reports from Docket (ernestdefoe/docket) on the list and the member page, through Docket's own
 * visibility. Users: 1 admin, 2 normal (no flag permissions, but may open Member Desk), 3 mod
 * (Moderators: discussion.viewFlags by default), 4 target (reported), 5 reporter.
 * Posts 1–5 by the target in discussion 1. Reports 1 (open, 2 reporters), 2 (in progress, assigned
 * to the mod), 3 (resolved, with an outcome); report 4 is against the reporter, filed by the target.
 */
class ReportsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-flags', 'ernestdefoe-docket', 'ernestdefoe-member-desk');

        $now = Carbon::now();
        $post = fn (int $id, int $userId) => ['id' => $id, 'discussion_id' => 1, 'number' => $id, 'user_id' => $userId, 'type' => 'comment', 'content' => '<t><p>p'.$id.'</p></t>', 'created_at' => $now, 'is_private' => 0];
        $report = fn (int $id, int $postId, int $userId, string $status, array $extra = []) => $extra + [
            'id' => $id, 'post_id' => $postId, 'discussion_id' => 1, 'user_id' => $userId, 'status' => $status, 'entry_count' => 1,
            'created_at' => $now->copy()->subDays($id), 'updated_at' => $now,
        ];
        $entry = fn (int $id, int $reportId, int $postId, int $by, string $reason) => ['id' => $id, 'report_id' => $reportId, 'post_id' => $postId, 'user_id' => $by, 'type' => 'user', 'reason' => $reason, 'created_at' => $now->copy()->subDays($reportId)];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'target', 'email' => 'target@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 5],
                ['id' => 5, 'username' => 'reporter', 'email' => 'reporter@machine.local', 'is_email_confirmed' => 1, 'comment_count' => 1],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            'group_permission' => [
                ['permission' => 'ernestdefoe-member-desk.view', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'ernestdefoe-member-desk.view', 'group_id' => Group::MEMBER_ID],
            ],
            'discussions' => [['id' => 1, 'title' => 'Gear swap', 'slug' => 'gear-swap', 'user_id' => 5, 'created_at' => $now, 'comment_count' => 6, 'participant_count' => 2, 'first_post_id' => 6, 'last_post_number' => 6, 'is_private' => 0]],
            'posts' => [$post(1, 4), $post(2, 4), $post(3, 4), $post(4, 4), $post(5, 4), $post(6, 5)],
            'docket_reports' => [
                $report(1, 1, 4, 'open', ['entry_count' => 2]),
                $report(2, 2, 4, 'in_progress', ['assignee_id' => 3]),
                $report(3, 3, 4, 'resolved', ['outcome' => 'Edited the post.', 'closed_at' => $now, 'closed_by_id' => 3]),
                $report(4, 6, 5, 'open'),
            ],
            'docket_entries' => [
                $entry(1, 1, 1, 5, 'spam'), $entry(2, 1, 1, 2, 'spam'), $entry(3, 2, 2, 5, 'off_topic'), $entry(4, 3, 3, 5, 'inappropriate'),
                $entry(5, 4, 6, 4, 'inappropriate'),
            ],
        ]);
    }

    /** @return array{int, mixed} */
    private function call(string $path, int $as): array
    {
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $params);
        $response = $this->send($this->request('GET', '/api/member-desk'.$route, ['authenticatedAs' => $as])->withQueryParams($params));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function db(): ConnectionInterface
    {
        return $this->app()->getContainer()->make(ConnectionInterface::class);
    }

    #[Test]
    public function the_list_counts_reports_and_filters_by_them(): void
    {
        [, $body] = $this->call('/members?q=target', 1);
        $this->assertSame('docket', $body['reports']);
        $this->assertSame(['total' => 3, 'open' => 2], $body['rows'][0]['reports']);

        [, $open] = $this->call('/members?status[]=reported', 1);
        $this->assertEqualsCanonicalizing([4, 5], array_column($open['rows'], 'id'));

        [, $many] = $this->call('/members?reportedMin=2&reportedWithin=P30D', 1);
        $this->assertSame([4], array_column($many['rows'], 'id'));

        [, $recent] = $this->call('/members?reportedMin=2&reportedWithin=P1D', 1);
        $this->assertSame(0, $recent['total'], 'reports older than a day do not count');
    }

    #[Test]
    public function the_member_page_shows_reports_against_and_filed(): void
    {
        [, $body] = $this->call('/members/4', 3);
        $r = $body['reports'];
        $this->assertSame('docket', $r['source']);
        $this->assertSame(3, $r['total']);
        $this->assertSame(2, $r['open']);
        $this->assertSame(['open' => 2], ['open' => $body['member']['reports']['open']]);
        $byStatus = array_column($r['against'], null, 'status');
        $this->assertSame('mod', $byStatus['in_progress']['assignee']);
        $this->assertSame('Edited the post.', $byStatus['resolved']['outcome']);
        $this->assertSame(2, $byStatus['open']['reports']);
        $this->assertSame([4], array_column($r['filed'], 'reportId'));
        $this->assertSame('reporter', $r['filed'][0]['about']);
    }

    #[Test]
    public function staff_without_flag_permissions_see_no_reports_and_cannot_filter_by_them(): void
    {
        [$status, $body] = $this->call('/members?q=target', 2);
        $this->assertSame(200, $status);
        $this->assertNull($body['reports']);
        $this->assertNull($body['rows'][0]['reports']);

        [, $all] = $this->call('/members?status[]=reported', 2);
        $this->assertSame(5, $all['total'], 'the filter is ignored, not answered');

        [, $member] = $this->call('/members/4', 2);
        $this->assertNull($member['reports']);
    }

    #[Test]
    public function a_report_docket_hides_from_a_viewer_is_not_counted_for_them(): void
    {
        // Docket's own rule: a report on a post the viewer can't see isn't theirs to see.
        $this->db()->table('posts')->where('id', 1)->update(['hidden_at' => Carbon::now(), 'hidden_user_id' => 1]);
        $this->db()->table('group_permission')->where('permission', 'discussion.viewHiddenPosts')->delete();
        $this->db()->table('group_permission')->where('permission', 'discussion.hidePosts')->delete();

        [, $mod] = $this->call('/members?q=target', 3);
        [, $admin] = $this->call('/members?q=target', 1);
        $this->assertSame(3, $admin['rows'][0]['reports']['total']);
        $this->assertSame(2, $mod['rows'][0]['reports']['total'], 'the report on the hidden post is not counted for the moderator');
        [, $page] = $this->call('/members/4', 3);
        $this->assertNotContains(1, array_column($page['reports']['against'], 'id'));
        [, $filter] = $this->call('/members?reportedMin=3', 3);
        $this->assertSame(0, $filter['total'], 'nor can the moderator find the member through it');
    }

    #[Test]
    public function the_member_page_cost_does_not_grow_with_reports(): void
    {
        $count = function (): int {
            $db = $this->db();
            $this->call('/members/4', 1);
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->call('/members/4', 1);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();

            return $n;
        };

        $three = $count();

        $now = Carbon::now();
        for ($i = 10; $i < 20; $i++) {
            $this->db()->table('posts')->insert(['id' => $i, 'discussion_id' => 1, 'number' => $i, 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>x</p></t>', 'created_at' => $now, 'is_private' => 0]);
            $this->db()->table('docket_reports')->insert(['id' => $i, 'post_id' => $i, 'discussion_id' => 1, 'user_id' => 4, 'status' => 'in_progress', 'assignee_id' => 3, 'entry_count' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->db()->table('docket_entries')->insert(['report_id' => $i, 'post_id' => $i, 'user_id' => 5, 'type' => 'user', 'reason' => 'spam', 'created_at' => $now]);
        }

        $this->assertSame($three, $count(), '3 reports or 13: the same queries');
    }
}
