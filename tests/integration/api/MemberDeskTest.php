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
 * Users: 1 admin, 2 normal, 3 mod (Moderators group), 4 spam1, 5 spam2 (share 198.51.100.23),
 * 6 innocent (also posted once from 198.51.100.77, which spam1 used too), 7 quiet.
 * Discussion 1 by spam1 with posts 1–2 (spam1), 3 (spam2), 4 (innocent).
 */
class MemberDeskTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-suspend', 'ernestdefoe-member-desk');

        $now = Carbon::now();
        $user = fn (int $id, string $name, array $extra = []) => $extra + [
            'id' => $id, 'username' => $name, 'email' => "$name@machine.local", 'is_email_confirmed' => 1,
            'joined_at' => $now->copy()->subDays(10 * $id), 'last_seen_at' => $now->copy()->subHours($id),
            'comment_count' => 0, 'discussion_count' => 0, 'preferences' => json_encode(['discloseOnline' => false]),
        ];
        $post = fn (int $id, int $userId, int $number, string $ip) => [
            'id' => $id, 'discussion_id' => 1, 'number' => $number, 'user_id' => $userId, 'type' => 'comment',
            'content' => '<t><p>post '.$id.'</p></t>', 'created_at' => $now->copy()->subHours(10 - $id), 'ip_address' => $ip, 'is_private' => 0,
        ];
        $token = fn (int $userId, string $ip) => [
            'token' => bin2hex(random_bytes(20)), 'user_id' => $userId, 'last_activity_at' => $now, 'created_at' => $now,
            'type' => 'session', 'last_ip_address' => $ip, 'last_user_agent' => 'test',
        ];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                $user(3, 'mod'),
                $user(4, 'spam1', ['is_email_confirmed' => 0, 'comment_count' => 2, 'discussion_count' => 1]),
                $user(5, 'spam2', ['is_email_confirmed' => 0, 'comment_count' => 1]),
                $user(6, 'innocent', ['comment_count' => 1]),
                $user(7, 'quiet'),
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            'group_permission' => [
                ['permission' => 'ernestdefoe-member-desk.view', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'ernestdefoe-member-desk.viewIps', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'ernestdefoe-member-desk.markSpammer', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'user.suspend', 'group_id' => Group::MODERATOR_ID],
            ],
            'discussions' => [
                ['id' => 1, 'title' => 'Deals', 'slug' => 'deals', 'user_id' => 4, 'created_at' => $now, 'comment_count' => 4, 'participant_count' => 3, 'first_post_id' => 1, 'last_post_number' => 4, 'is_private' => 0],
            ],
            'posts' => [
                $post(1, 4, 1, '198.51.100.23'),
                $post(2, 4, 2, '198.51.100.77'),
                $post(3, 5, 3, '198.51.100.23'),
                $post(4, 6, 4, '198.51.100.77'),
            ],
            'access_tokens' => [$token(4, '198.51.100.23'), $token(5, '198.51.100.23'), $token(6, '203.0.113.6')],
        ]);
    }

    /**
     * A bulk action runs each member through core's own user update/delete, so per member core
     * loads that member (UserResource::find) and flarum/suspend's notification loads them again.
     * That is one action per member, not a page render; the list's own cost is pinned by
     * the_list_is_a_fixed_number_of_queries(), which counts every query directly.
     *
     * @return string[]
     */
    protected function allowedRepeatedQueries(): array
    {
        return ['users"."id" = ? limit ?', 'users`.`id` = ? limit ?'];
    }

    /** @return array{int, mixed} */
    private function call(string $method, string $path, ?int $as, array $body = []): array
    {
        $options = $as ? ['authenticatedAs' => $as] : [];
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        if ($method !== 'GET') {
            $options['json'] = $body;
        }
        parse_str($query, $params);
        $response = $this->send($this->request($method, '/api/member-desk'.$route, $options)->withQueryParams($params));
        $raw = (string) $response->getBody();

        return [$response->getStatusCode(), json_decode($raw, true) ?? $raw];
    }

    private function db(): ConnectionInterface
    {
        return $this->app()->getContainer()->make(ConnectionInterface::class);
    }

    #[Test]
    public function only_staff_with_the_permission_can_open_it(): void
    {
        [$status] = $this->call('GET', '/members', null);
        $this->assertContains($status, [401, 403]);
        [$status] = $this->call('GET', '/members', 2);
        $this->assertSame(403, $status);
        [$status, $body] = $this->call('GET', '/members', 3);
        $this->assertSame(200, $status);
        $this->assertSame(7, $body['total']);
        [$status] = $this->call('GET', '/members/4', 2);
        $this->assertSame(403, $status);
        [$status] = $this->call('POST', '/run', 2, ['action' => 'confirm', 'target' => ['ids' => [4]]]);
        $this->assertSame(403, $status);
    }

    #[Test]
    public function moderators_see_masked_emails_and_cannot_search_or_sort_by_them(): void
    {
        // Moderators may see hidden last-seen times by default; take that away before the first
        // request (permissions are read once per actor).
        $this->db()->table('group_permission')->where('permission', 'user.viewLastSeenAt')->delete();

        [, $body] = $this->call('GET', '/members?q=quiet', 3);
        $this->assertSame('q•••@machine.local', $body['rows'][0]['email']);
        // No flags and no Docket here: no report column, no Reports tab.
        $this->assertNull($body['rows'][0]['reports']);

        // Searching an email fragment must not answer for a moderator (it would leak the address).
        [, $body] = $this->call('GET', '/members?q=machine.local', 3);
        $this->assertSame(0, $body['total']);
        [, $admin] = $this->call('GET', '/members?q=machine.local', 1);
        $this->assertGreaterThan(0, $admin['total']);

        // Hidden online status: the last-seen time is not sent to staff without user.viewLastSeenAt.
        [, $body] = $this->call('GET', '/members?q=quiet', 3);
        $this->assertNull($body['rows'][0]['lastSeenAt']);
        [, $body] = $this->call('GET', '/members?q=quiet', 1);
        $this->assertNotNull($body['rows'][0]['lastSeenAt']);
    }

    #[Test]
    public function a_hidden_group_cannot_be_used_to_list_its_members(): void
    {
        $this->db()->table('groups')->insert(['id' => 11, 'name_singular' => 'Secret', 'name_plural' => 'Secrets', 'is_hidden' => 1]);
        $this->db()->table('group_user')->insert(['user_id' => 7, 'group_id' => 11]);
        // An explicit Member row still counts as "no extra group".
        $this->db()->table('group_user')->insert(['user_id' => 6, 'group_id' => Group::MEMBER_ID]);

        [, $mod] = $this->call('GET', '/members?groups[]=11', 3);
        $this->assertSame(0, $mod['total'], 'a moderator without viewHiddenGroups learns nothing');
        [, $quiet] = $this->call('GET', '/members?q=quiet', 3);
        $this->assertNotContains(11, $quiet['rows'][0]['groups']);

        [, $admin] = $this->call('GET', '/members?groups[]=11', 1);
        $this->assertSame([7], array_column($admin['rows'], 'id'));

        [, $none] = $this->call('GET', '/members?groups[]=none&q=innocent', 1);
        $this->assertSame(1, $none['total']);
        $this->assertSame([], $none['rows'][0]['groups']);
    }

    #[Test]
    public function the_log_shows_a_moderator_only_what_the_list_would(): void
    {
        // Moderators lose user.viewLastSeenAt (they have it by default) and have no viewHiddenGroups.
        $this->db()->table('group_permission')->where('permission', 'user.viewLastSeenAt')->delete();
        $this->db()->table('groups')->insert(['id' => 11, 'name_singular' => 'Secret', 'name_plural' => 'Secrets', 'is_hidden' => 1]);
        $this->db()->table('group_user')->insert(['user_id' => 7, 'group_id' => 11]);

        // The admin acts: a group change on the hidden group, and a spammer snapshot of member 7.
        $this->call('POST', '/run', 1, ['action' => 'addGroup', 'target' => ['ids' => [6]], 'params' => ['group' => 11]]);
        $this->call('POST', '/run', 1, ['action' => 'suspend', 'target' => ['ids' => [7]], 'params' => ['until' => 3, 'reason' => 'STAFF-ONLY-REASON']]);
        $this->call('POST', '/run', 1, ['action' => 'spammer', 'target' => ['ids' => [7]]]);

        $this->db()->table('group_permission')->where('permission', 'user.suspend')->delete();

        foreach (['/log', '/members/7', '/members/6'] as $path) {
            [$status, $mod] = $this->call('GET', $path, 3);
            $this->assertSame(200, $status);
            $json = json_encode($path === '/log' ? $mod['rows'] : $mod['log']);
            $this->assertStringNotContainsString('lastSeenAt', $json, "$path: last seen");
            $this->assertStringNotContainsString('Secret', $json, "$path: hidden group name");
            $this->assertStringNotContainsString('"group":11', $json, "$path: hidden group id");
            $this->assertStringNotContainsString('STAFF-ONLY-REASON', $json, "$path: suspend reason");
        }

        [, $admin] = $this->call('GET', '/log', 1);
        $json = json_encode($admin['rows']);
        $this->assertStringContainsString('lastSeenAt', $json);
        $this->assertStringContainsString('Secret', $json);
        $this->assertStringContainsString('STAFF-ONLY-REASON', $json);
    }

    #[Test]
    public function ip_search_needs_the_ip_permission(): void
    {
        [, $body] = $this->call('GET', '/members?q=198.51.100.23', 3);
        $this->assertEqualsCanonicalizing([4, 5], array_column($body['rows'], 'id'));

        // A helper who may open Member Desk but not see IPs (group 10, set up below).
        $this->db()->table('groups')->insert(['id' => 10, 'name_singular' => 'Helper', 'name_plural' => 'Helpers', 'is_hidden' => 0]);
        $this->db()->table('group_permission')->insert(['permission' => 'ernestdefoe-member-desk.view', 'group_id' => 10]);
        $this->db()->table('group_user')->insert(['user_id' => 2, 'group_id' => 10]);
        [, $body] = $this->call('GET', '/members?q=198.51.100.23', 2);
        $this->assertSame(0, $body['total']);
        [, $body] = $this->call('GET', '/members?q=spam1', 2);
        $this->assertNull($body['rows'][0]['lastIp']);
        [, $body] = $this->call('GET', '/members/4', 2);
        $this->assertNull($body['ips']);
        [, $body] = $this->call('GET', '/members?status[]=banned', 2);
        $this->assertSame(7, $body['total'], 'the banned-IP filter is ignored without the IP permission');
    }

    #[Test]
    public function the_list_is_a_fixed_number_of_queries(): void
    {
        $counts = [];
        foreach ([1, 3] as $as) {
            foreach ([10, 50] as $per) {
                $db = $this->db();
                // Warm the actor's permissions, then count only the request.
                $this->call('GET', '/members?perPage=10', $as);
                $db->flushQueryLog();
                $db->enableQueryLog();
                $this->call('GET', "/members?perPage=$per&rowsOnly=1", $as);
                $counts["$as-$per"] = count($db->getQueryLog());
                $db->disableQueryLog();
            }
        }

        // Add 60 members; the count for a full page must not move.
        $now = Carbon::now();
        $rows = [];
        for ($i = 100; $i < 160; $i++) {
            $rows[] = ['id' => $i, 'username' => "bulk$i", 'email' => "bulk$i@machine.local", 'is_email_confirmed' => 1, 'joined_at' => $now, 'password' => 'x'];
        }
        $this->db()->table('users')->insert($rows);
        foreach ($rows as $r) {
            $this->db()->table('group_user')->insert(['user_id' => $r['id'], 'group_id' => Group::MODERATOR_ID]);
            $this->db()->table('access_tokens')->insert(['token' => bin2hex(random_bytes(20)), 'user_id' => $r['id'], 'last_activity_at' => $now, 'created_at' => $now, 'type' => 'session', 'last_ip_address' => '203.0.113.'.($r['id'] - 90)]);
        }
        $db = $this->db();
        $db->flushQueryLog();
        $db->enableQueryLog();
        [, $body] = $this->call('GET', '/members?perPage=50&rowsOnly=1', 1);
        $big = count($db->getQueryLog());
        $db->disableQueryLog();

        $this->assertCount(50, $body['rows']);
        $this->assertSame($counts['1-10'], $counts['1-50']);
        $this->assertSame($counts['3-10'], $counts['3-50']);
        $this->assertSame($counts['1-50'], $big, 'a 50-row page costs the same with 7 or 67 members');
    }

    #[Test]
    public function spammer_hides_content_bans_only_unshared_ips_and_restores_exactly(): void
    {
        // A post someone else hid earlier must stay hidden after restore.
        $this->db()->table('posts')->where('id', 2)->update(['hidden_at' => Carbon::now(), 'hidden_user_id' => 1]);

        [$status, $body] = $this->call('POST', '/run', 3, ['action' => 'spammer', 'target' => ['ids' => [4, 5]]]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(2, $body['done']);

        $this->assertNotNull(User::find(4)->suspended_until);
        $this->assertNotNull($this->db()->table('posts')->where('id', 1)->value('hidden_at'));
        $this->assertNotNull($this->db()->table('posts')->where('id', 3)->value('hidden_at'));
        $this->assertNull($this->db()->table('posts')->where('id', 4)->value('hidden_at'), 'the innocent post stays');
        $this->assertNotNull($this->db()->table('discussions')->where('id', 1)->value('hidden_at'));

        // Restore spam1: only what the action hid comes back.
        [$status, $body] = $this->call('POST', '/run', 3, ['action' => 'unspam', 'target' => ['ids' => [4]]]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(1, $body['done'], json_encode($body));
        $this->assertNull($this->db()->table('posts')->where('id', 1)->value('hidden_at'));
        $this->assertNotNull($this->db()->table('posts')->where('id', 2)->value('hidden_at'), 'hidden by someone else before: stays hidden');
        $this->assertNull($this->db()->table('discussions')->where('id', 1)->value('hidden_at'));
        $this->assertNull(User::find(4)->suspended_until);

        // A second restore has nothing to undo.
        [, $body] = $this->call('POST', '/run', 3, ['action' => 'unspam', 'target' => ['ids' => [4]]]);
        $this->assertSame('nothing', $body['failed'][0]['reason']);
    }

    #[Test]
    public function nobody_acts_on_themselves_or_an_admin(): void
    {
        [, $body] = $this->call('POST', '/run', 3, ['action' => 'suspend', 'target' => ['ids' => [1, 3, 7]], 'params' => ['until' => 7]]);
        $this->assertSame(1, $body['done']);
        $this->assertEqualsCanonicalizing(['admin', 'self'], array_column($body['failed'], 'reason'));
        $this->assertNull(User::find(1)->suspended_until);
        $this->assertNotNull(User::find(7)->suspended_until);
    }

    #[Test]
    public function moderators_cannot_erase_or_change_groups_without_permission(): void
    {
        foreach (['anonymize', 'delete', 'addGroup'] as $action) {
            [$status] = $this->call('POST', '/run', 3, ['action' => $action, 'target' => ['ids' => [7]], 'params' => ['group' => Group::MODERATOR_ID]]);
            $this->assertSame(422, $status, $action);
        }
        $this->assertNotNull(User::find(7));
    }

    #[Test]
    public function the_confirmed_count_must_still_match(): void
    {
        [$status] = $this->call('POST', '/run', 1, ['action' => 'confirm', 'target' => ['all' => true, 'filters' => ['status' => ['unconfirmed']]], 'expect' => 5]);
        $this->assertSame(422, $status);
        [$status, $body] = $this->call('POST', '/run', 1, ['action' => 'confirm', 'target' => ['all' => true, 'filters' => ['status' => ['unconfirmed']]], 'expect' => 2]);
        $this->assertSame(200, $status);
        $this->assertSame(2, $body['done']);
        $this->assertTrue((bool) User::find(4)->is_email_confirmed);
    }

    #[Test]
    public function group_changes_go_through_core_and_are_logged(): void
    {
        [, $body] = $this->call('POST', '/run', 1, ['action' => 'addGroup', 'target' => ['ids' => [7]], 'params' => ['group' => Group::MODERATOR_ID]]);
        $this->assertSame(1, $body['done']);
        $this->assertTrue(User::find(7)->groups()->where('groups.id', Group::MODERATOR_ID)->exists());
        $entry = AuditEntry::query()->where('action', 'addGroup')->first();
        $this->assertSame(7, $entry->user_id);
        $this->assertSame('done', $entry->status);
    }

    #[Test]
    public function without_gdpr_delete_is_cores_and_anonymize_is_not_offered(): void
    {
        [$status] = $this->call('POST', '/run', 1, ['action' => 'anonymize', 'target' => ['ids' => [7]]]);
        $this->assertSame(422, $status);

        [$status, $body] = $this->call('POST', '/run', 1, ['action' => 'delete', 'target' => ['ids' => [6]]]);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertNull(User::find(6));
        // Core keeps the posts, without an author.
        $this->assertTrue($this->db()->table('posts')->where('id', 4)->exists());

        $entry = AuditEntry::query()->where('action', 'delete')->first();
        $this->assertTrue($entry->erased);
        $this->assertArrayNotHasKey('email', $entry->before);
        $this->assertStringNotContainsString('innocent@machine.local', json_encode($entry->before));
        $this->assertSame(64, strlen($entry->before['emailHash']));
    }

    #[Test]
    public function erased_hashes_are_salted_and_checkable_by_admins_only(): void
    {
        $this->call('POST', '/run', 1, ['action' => 'delete', 'target' => ['ids' => [6]]]);
        $entry = AuditEntry::query()->where('action', 'delete')->first();

        $salt = $this->db()->table('settings')->where('key', 'ernestdefoe-member-desk.salt')->value('value');
        $this->assertSame(64, strlen((string) $salt));
        $this->assertSame(hash_hmac('sha256', 'innocent@machine.local', $salt), $entry->before['emailHash']);
        $this->assertNotSame(hash('sha256', 'innocent@machine.local'), $entry->before['emailHash'], 'not a bare hash');
        $this->assertContains(hash_hmac('sha256', '198.51.100.77', $salt), $entry->before['ipHashes']);

        [, $found] = $this->call('POST', '/hash-check', 1, ['value' => 'Innocent@machine.local']);
        $this->assertCount(1, $found['matches']);
        [, $none] = $this->call('POST', '/hash-check', 1, ['value' => 'nobody@machine.local']);
        $this->assertCount(0, $none['matches']);
        [$status] = $this->call('POST', '/hash-check', 3, ['value' => 'innocent@machine.local']);
        $this->assertSame(403, $status);

        // The log never sends a hash to the browser.
        [, $log] = $this->call('GET', '/log', 1);
        $this->assertStringNotContainsString($entry->before['emailHash'], json_encode($log));
    }

    #[Test]
    public function prune_strips_erased_entries_after_the_retention_period(): void
    {
        $this->call('POST', '/run', 1, ['action' => 'delete', 'target' => ['ids' => [6]]]);
        $this->call('POST', '/run', 1, ['action' => 'delete', 'target' => ['ids' => [7]]]);
        AuditEntry::query()->where('user_id', 6)->update(['created_at' => Carbon::now()->subDays(91)]);

        $command = $this->app()->getContainer()->make(\ErnestDefoe\MemberDesk\Console\PruneCommand::class);
        $command->setLaravel($this->app()->getContainer());
        $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\NullOutput());

        $old = AuditEntry::query()->where('user_id', 6)->first();
        $this->assertTrue($old->before['pruned']);
        $this->assertArrayNotHasKey('username', $old->before);
        $this->assertArrayNotHasKey('emailHash', $old->before);

        $recent = AuditEntry::query()->where('user_id', 7)->first();
        $this->assertSame('quiet', $recent->before['username']);
    }

    #[Test]
    public function saved_filters_are_private_unless_an_admin_shares_them(): void
    {
        [, $list] = $this->call('POST', '/filters', 3, ['name' => 'Mine', 'shared' => true, 'query' => ['status' => ['unconfirmed']]]);
        $mine = collect($list)->firstWhere('name', 'Mine');
        $this->assertFalse($mine['shared'], 'a moderator cannot share');

        [, $adminList] = $this->call('GET', '/filters', 1);
        $this->assertNull(collect($adminList)->firstWhere('name', 'Mine'));

        [$status] = $this->call('DELETE', '/filters/'.$mine['id'], 1);
        $this->assertSame(403, $status);
    }

    #[Test]
    public function csv_export_is_admin_only_and_neutralises_formulas(): void
    {
        $this->db()->table('users')->where('id', 7)->update(['username' => '=cmd']);
        $response = $this->send($this->request('GET', '/api/member-desk/export', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode());
        $csv = (string) $response->getBody();
        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringContainsString('quiet@machine.local', $csv);

        $this->assertSame(403, $this->send($this->request('GET', '/api/member-desk/export', ['authenticatedAs' => 3]))->getStatusCode());
        $this->assertSame('export', AuditEntry::query()->value('action'));
    }
}
