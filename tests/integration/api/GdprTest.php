<?php

namespace ErnestDefoe\MemberDesk\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Against the real flarum/gdpr: anonymize and delete go through its own erasure (the replaced
 * DELETE endpoint, `gdprMode`), and its settings are honoured even though its endpoint doesn't
 * check them. Users: 1 admin, 2 normal, 4 target (two posts, in group 5).
 */
class GdprTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-gdpr', 'ernestdefoe-member-desk');

        $now = Carbon::now();
        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 4, 'username' => 'target', 'email' => 'target@machine.local', 'is_email_confirmed' => 1, 'joined_at' => $now->copy()->subYear(), 'comment_count' => 2],
            ],
            Group::class => [['id' => 5, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters', 'is_hidden' => 0]],
            'group_user' => [['user_id' => 4, 'group_id' => 5]],
            'discussions' => [
                ['id' => 1, 'title' => 'Hello', 'slug' => 'hello', 'user_id' => 2, 'created_at' => $now, 'comment_count' => 3, 'participant_count' => 2, 'first_post_id' => 1, 'last_post_number' => 3, 'is_private' => 0],
            ],
            'posts' => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>a</p></t>', 'created_at' => $now, 'is_private' => 0],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>b</p></t>', 'created_at' => $now, 'is_private' => 0, 'ip_address' => '192.0.2.44'],
                ['id' => 3, 'discussion_id' => 1, 'number' => 3, 'user_id' => 4, 'type' => 'comment', 'content' => '<t><p>c</p></t>', 'created_at' => $now, 'is_private' => 0],
            ],
            'member_desk_audit' => [
                ['id' => 900, 'actor_id' => 1, 'action' => 'addGroup', 'user_id' => 4, 'status' => 'done', 'before' => json_encode(['username' => 'target', 'groups' => []]), 'after' => json_encode(['group' => 5]), 'erased' => false, 'created_at' => $now->copy()->subDay()],
            ],
        ]);
    }

    private function runAction(string $action, int $as = 1, array $ids = [4]): array
    {
        $response = $this->send($this->request('POST', '/api/member-desk/run', ['authenticatedAs' => $as, 'json' => ['action' => $action, 'target' => ['ids' => $ids]]]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    protected function allowedRepeatedQueries(): array
    {
        // gdpr's erasure job walks every data type once for the member it erases.
        return ['users"."id" = ? limit ?', 'users`.`id` = ? limit ?'];
    }

    #[Test]
    public function anonymize_runs_gdprs_erasure_and_keeps_the_posts(): void
    {
        [$status, $body] = $this->runAction('anonymize');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(1, $body['done'], json_encode($body));

        $user = User::find(4);
        $this->assertTrue((bool) $user->anonymized);
        $this->assertStringStartsWith('Anonymous', $user->username);
        $this->assertSame(0, $user->groups()->count());
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $this->assertSame(2, $db->table('posts')->where('user_id', 4)->count(), 'posts stay');
        $this->assertSame('anonymization', $db->table('gdpr_erasure')->where('user_id', 4)->value('processed_mode'));

        $entry = AuditEntry::query()->where('action', 'anonymize')->first();
        $this->assertSame('done', $entry->status, 'gdpr\'s Erased event marks the entry done');
        $this->assertTrue($entry->erased);
        $this->assertSame('target', $entry->before['username']);
        $this->assertStringNotContainsString('target@machine.local', json_encode($entry->before));
        $this->assertStringNotContainsString('192.0.2.44', json_encode($entry->before));

        // The member's earlier entry no longer carries their name.
        $earlier = AuditEntry::query()->find(900);
        $this->assertArrayNotHasKey('username', $earlier->before ?? []);
    }

    #[Test]
    public function delete_is_refused_while_gdprs_deletion_setting_is_off(): void
    {
        // gdpr's own default: anonymization on, deletion off. Its endpoint would accept the
        // request and its queued job would then fail, so Member Desk refuses up front.
        [$status] = $this->runAction('delete');
        $this->assertSame(422, $status);
        $this->assertNotNull(User::find(4));

        $preview = $this->send($this->request('POST', '/api/member-desk/preview', ['authenticatedAs' => 1, 'json' => ['action' => 'anonymize', 'target' => ['ids' => [4]]]]));
        $p = json_decode((string) $preview->getBody(), true);
        $this->assertTrue($p['modes']['anonymize']);
        $this->assertFalse($p['modes']['delete']);
        $this->assertSame(2, $p['posts']);
    }

    #[Test]
    public function delete_runs_gdprs_deletion_when_it_is_allowed(): void
    {
        $this->setting('flarum-gdpr.allow-deletion', '1');

        [$status, $body] = $this->runAction('delete');
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(1, $body['done'], json_encode($body));
        $this->assertNull(User::find(4));
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $this->assertSame(0, $db->table('posts')->where('user_id', 4)->count(), 'gdpr deletes their posts');
        $this->assertSame('done', AuditEntry::query()->where('action', 'delete')->value('status'));
    }

    #[Test]
    public function anonymize_is_refused_when_gdpr_turns_it_off(): void
    {
        $this->setting('flarum-gdpr.allow-anonymization', '0');
        [$status] = $this->runAction('anonymize');
        $this->assertSame(422, $status);
        $this->assertFalse((bool) User::find(4)->anonymized);
    }

    #[Test]
    public function an_erasure_done_elsewhere_is_logged_too(): void
    {
        // gdpr's own path (here: core's DELETE with gdprMode, as gdpr's modal sends it).
        $response = $this->send($this->request('DELETE', '/api/users/4', ['authenticatedAs' => 1, 'json' => ['gdprMode' => 'anonymization']]));
        $this->assertContains($response->getStatusCode(), [200, 204]);

        $entry = AuditEntry::query()->where('action', 'anonymize')->first();
        $this->assertNotNull($entry);
        $this->assertSame('gdpr', $entry->after['via']);
        $this->assertSame(64, strlen($entry->before['emailHash']));
    }

    #[Test]
    public function the_installer_admins_own_member_page_opens(): void
    {
        // JoZ3 (Discuss d/40368/5): GET /api/member-desk/members/1 500'd for the first admin.
        // Excerpts come from the stored XML, never rendered: a tag no installed extension renders
        // (here an upload preview) must not reach any formatter code.
        // The real cause on ernestdefoe.online: admins rename discussions, and the rename event post
        // came back with the comments ("Call to undefined method DiscussionRenamedPost::formatContent").
        $this->app()->getContainer()->make(ConnectionInterface::class)->table('posts')->insert([
            'id' => 8, 'discussion_id' => 1, 'number' => 7, 'user_id' => 1, 'type' => 'discussionRenamed', 'created_at' => Carbon::now()->addMinute(), 'is_private' => 0,
            'content' => json_encode(['Old', 'Hello']),
        ]);
        $this->app()->getContainer()->make(ConnectionInterface::class)->table('posts')->insert([
            'id' => 9, 'discussion_id' => 1, 'number' => 4, 'user_id' => 1, 'type' => 'comment', 'created_at' => Carbon::now(), 'is_private' => 0,
            'content' => '<r><p>Setup <UPL-IMAGE-PREVIEW url="https://x.test/a.png"><s>[upl-image-preview url=https://x.test/a.png]</s></UPL-IMAGE-PREVIEW> done <USERMENTION displayname="target" id="4">@"target"#4</USERMENTION></p></r>',
        ]);
        $body = json_decode((string) $this->send($this->request('GET', '/api/member-desk/members/1', ['authenticatedAs' => 1]))->getBody(), true);
        $this->assertSame([9], array_column($body['activity']['posts'], 'id'), 'comments only');
        $this->assertSame('Setup done @target', $body['activity']['posts'][0]['excerpt'] ?? '', json_encode($body['activity'] ?? null));
        foreach ([1, 4] as $id) {
            $response = $this->send($this->request('GET', "/api/member-desk/members/$id", ['authenticatedAs' => 1]));
            $this->assertSame(200, $response->getStatusCode(), "member $id: ".substr((string) $response->getBody(), 0, 600));
        }
    }

    #[Test]
    public function an_imported_admin_with_odd_data_still_opens(): void
    {
        // JoZ3 again: user 1 came from Vanilla through Nitro-Porter. Imported rows carry dates
        // no forum wrote (0000-00-00, year 1000), posts stored as HTML or plain text instead of
        // formatter XML (rendering those throws "Cannot load XML"), and preferences that aren't JSON.
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $odd = '1000-01-01 00:00:00';
        $db->table('users')->where('id', 1)->update([
            'joined_at' => $odd, 'last_seen_at' => $odd, 'marked_all_as_read_at' => $odd,
            // users.email is NOT NULL on every driver, so an import leaves it empty instead.
            'email' => '', 'avatar_url' => '/uploads/userpics/123/nABC123.jpg',
        ]);
        if ($db->getDriverName() === 'mysql') {
            $db->statement("SET SESSION sql_mode = ''");
            $db->table('users')->where('id', 1)->update(['joined_at' => '0000-00-00 00:00:00']);
        }
        if ($db->getDriverName() === 'sqlite') {
            // MySQL and PostgreSQL JSON columns refuse it, so an import can't have written it there.
            $db->table('users')->where('id', 1)->update(['preferences' => 'not json']);
            // MySQL and PostgreSQL enforce the group foreign key; SQLite lets an import write it.
            $db->statement('PRAGMA foreign_keys = OFF');
            $db->table('group_user')->insert(['user_id' => 1, 'group_id' => 9999]);
        }
        $db->table('posts')->insert([
            ['id' => 10, 'discussion_id' => 1, 'number' => 5, 'user_id' => 1, 'type' => 'comment', 'created_at' => $odd, 'is_private' => 0, 'content' => 'Plain <b>Vanilla</b> & html'],
            ['id' => 11, 'discussion_id' => 1, 'number' => 6, 'user_id' => 1, 'type' => 'comment', 'created_at' => Carbon::now(), 'is_private' => 0, 'content' => '<t><p>Tom & Jerry</p></t>'],
        ]);
        $db->table('access_tokens')->insert(['token' => str_repeat('a', 40), 'user_id' => 1, 'type' => 'session', 'created_at' => $odd, 'last_activity_at' => $odd]);

        $response = $this->send($this->request('GET', '/api/member-desk/members/1', ['authenticatedAs' => 1]));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode(), substr((string) $response->getBody(), 0, 600));
        $this->assertNull($body['member']['joinedAt'], 'an impossible join date reads as unknown');
        $this->assertNotContains(9999, $body['member']['groups']);
        $excerpts = array_column($body['activity']['posts'], 'excerpt', 'id');
        $this->assertSame('Plain Vanilla & html', $excerpts[10] ?? null);
        $this->assertNull(array_column($body['activity']['posts'], 'createdAt', 'id')[10]);

        $list = $this->send($this->request('GET', '/api/member-desk/members', ['authenticatedAs' => 1]));
        $this->assertSame(200, $list->getStatusCode());
        $row = collect(json_decode((string) $list->getBody(), true)['rows'])->firstWhere('id', 1);
        $this->assertNull($row['joinedAt']);
    }

    #[Test]
    public function a_fresh_install_is_admin_only(): void
    {
        // Ernest, 2026-11-14: no group gets Member Desk until a forum owner grants it.
        $this->prepareDatabase(['group_user' => [['user_id' => 2, 'group_id' => Group::MODERATOR_ID]]]);
        $response = $this->send($this->request('GET', '/api/member-desk/members', ['authenticatedAs' => 2]));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->app()->getContainer()->make(ConnectionInterface::class)
            ->table('group_permission')->where('permission', 'like', 'ernestdefoe-member-desk.%')->count());
        $this->assertSame(200, $this->send($this->request('GET', '/api/member-desk/members', ['authenticatedAs' => 1]))->getStatusCode());
    }

    #[Test]
    public function the_member_page_shows_an_erasure_request(): void
    {
        $this->app()->getContainer()->make(ConnectionInterface::class)->table('gdpr_erasure')->insert([
            'user_id' => 4, 'status' => 'user_confirmed', 'created_at' => Carbon::now(), 'user_confirmed_at' => Carbon::now(), 'verification_token' => 'x',
        ]);
        $response = $this->send($this->request('GET', '/api/member-desk/members/4', ['authenticatedAs' => 1]));
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('user_confirmed', $body['erasure']['status']);
        $this->assertTrue($body['meta']['extensions']['gdpr']);
    }
}
