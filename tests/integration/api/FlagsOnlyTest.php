<?php

namespace ErnestDefoe\MemberDesk\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * flarum/flags without Docket: report counts with no statuses. Users: 1 admin, 3 mod, 4 target
 * (posts 1–2, both flagged), 5 reporter (flagged them; also has a post).
 */
class FlagsOnlyTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-flags', 'ernestdefoe-member-desk');

        $now = Carbon::now();
        $post = fn (int $id, int $userId) => ['id' => $id, 'discussion_id' => 1, 'number' => $id, 'user_id' => $userId, 'type' => 'comment', 'content' => '<t><p>p</p></t>', 'created_at' => $now, 'is_private' => 0];
        $this->prepareDatabase([
            User::class => [
                ['id' => 3, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'target', 'email' => 'target@machine.local', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'reporter', 'email' => 'reporter@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            'group_permission' => [['permission' => 'ernestdefoe-member-desk.view', 'group_id' => Group::MODERATOR_ID]],
            'discussions' => [['id' => 1, 'title' => 'Gear swap', 'slug' => 'gear-swap', 'user_id' => 5, 'created_at' => $now, 'comment_count' => 3, 'participant_count' => 2, 'first_post_id' => 3, 'last_post_number' => 3, 'is_private' => 0]],
            'posts' => [$post(1, 4), $post(2, 4), $post(3, 5)],
            'flags' => [
                ['id' => 1, 'post_id' => 1, 'type' => 'user', 'user_id' => 5, 'reason' => 'spam', 'created_at' => $now],
                ['id' => 2, 'post_id' => 2, 'type' => 'user', 'user_id' => 5, 'reason' => 'off_topic', 'created_at' => $now],
            ],
        ]);
    }

    #[Test]
    public function flags_give_counts_without_statuses(): void
    {
        $list = json_decode((string) $this->send($this->request('GET', '/api/member-desk/members', ['authenticatedAs' => 3])->withQueryParams(['q' => 'target']))->getBody(), true);
        $this->assertSame('flags', $list['reports']);
        $this->assertSame(['total' => 2, 'open' => 2], $list['rows'][0]['reports']);

        $open = json_decode((string) $this->send($this->request('GET', '/api/member-desk/members', ['authenticatedAs' => 3])->withQueryParams(['status' => ['reported']]))->getBody(), true);
        $this->assertSame([4], array_column($open['rows'], 'id'));

        $page = json_decode((string) $this->send($this->request('GET', '/api/member-desk/members/5', ['authenticatedAs' => 3]))->getBody(), true);
        $this->assertSame('flags', $page['reports']['source']);
        $this->assertSame(0, $page['reports']['total']);
        $this->assertCount(2, $page['reports']['filed']);
        $this->assertSame('target', $page['reports']['filed'][0]['about']);
    }
}
