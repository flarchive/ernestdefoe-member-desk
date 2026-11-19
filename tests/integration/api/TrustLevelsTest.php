<?php

namespace ErnestDefoe\MemberDesk\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Trust Levels (ernestdefoe/trust-levels): a member's level shows only to staff that extension lets
 * see it (its manage permission, or admins). Users: 1 admin, 2 helper (Member Desk only),
 * 3 mod (Member Desk + trust-levels.manage), 4 at level 3, 5 at level 1 and on hold, 6 never
 * placed (no row in trust_states: level 0), 100–119 at level 2. Fixtures fit either storage.
 */
class TrustLevelsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-trust-levels', 'ernestdefoe-member-desk');

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'mod', 'email' => 'mod@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'regular', 'email' => 'regular@machine.local', 'is_email_confirmed' => 1] + $this->trustColumns(['trust_level' => 3, 'trust_locked' => true]),
                ['id' => 5, 'username' => 'held', 'email' => 'held@machine.local', 'is_email_confirmed' => 1] + $this->trustColumns(['trust_level' => 1, 'trust_silenced_at' => Carbon::now()]),
                ['id' => 6, 'username' => 'fresh', 'email' => 'fresh@machine.local', 'is_email_confirmed' => 1],
                ...$this->extraMembers(),
            ],
            ...$this->trustStates([
                // The staff acting here have a row; member 6 has none (still level 0).
                ...array_map(fn ($id) => ['user_id' => $id, 'level' => 0, 'locked' => false, 'silenced_at' => null], [1, 2, 3]),
                ['user_id' => 4, 'level' => 3, 'locked' => true, 'silenced_at' => null],
                ['user_id' => 5, 'level' => 1, 'locked' => false, 'silenced_at' => Carbon::now()],
                ...array_map(fn ($i) => ['user_id' => 100 + $i, 'level' => 2, 'locked' => false, 'silenced_at' => null], range(0, 19)),
            ]),
            Group::class => [['id' => 10, 'name_singular' => 'Helper', 'name_plural' => 'Helpers', 'is_hidden' => 0]],
            'group_user' => [['user_id' => 2, 'group_id' => 10], ['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            'group_permission' => [
                ['permission' => 'ernestdefoe-member-desk.view', 'group_id' => 10],
                ['permission' => 'ernestdefoe-member-desk.view', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'ernestdefoe-trust-levels.manage', 'group_id' => Group::MODERATOR_ID],
            ],
        ]);
    }

    /** Trust Levels 1.1+ keeps each member's state in its own table; earlier versions in users.trust_*. */
    private function stateTable(): bool
    {
        return class_exists(\ErnestDefoe\TrustLevels\TrustState::class);
    }

    private function trustColumns(array $columns): array
    {
        return $this->stateTable() ? [] : $columns;
    }

    private function trustStates(array $rows): array
    {
        return $this->stateTable() ? ['trust_states' => $rows] : [];
    }

    /** Twenty more members at level 2 (ids 100–119), for the 1-vs-20 query count. */
    private function extraMembers(): array
    {
        return array_map(fn ($i) => ['id' => 100 + $i, 'username' => "extra$i", 'email' => "extra$i@machine.local", 'is_email_confirmed' => 1] + $this->trustColumns(['trust_level' => 2]), range(0, 19));
    }

    /** @return array<string, mixed> */
    private function list(int $as, array $params): array
    {
        return json_decode((string) $this->send($this->request('GET', '/api/member-desk/members', ['authenticatedAs' => $as])->withQueryParams($params))->getBody(), true);
    }

    #[Test]
    public function staff_trust_levels_lets_see_levels_get_them_and_can_filter(): void
    {
        foreach ([1, 3] as $as) {
            $body = $this->list($as, ['q' => 'regular']);
            $this->assertSame(['level' => 3, 'locked' => true, 'silenced' => false], $body['rows'][0]['trust']);
            $this->assertCount(5, $body['trustLevels']);

            $byLevel = $this->list($as, ['trust' => ['1']]);
            $this->assertSame([5], array_column($byLevel['rows'], 'id'));

            // Level 0 counts members Trust Levels has never placed (no row in its table).
            $new = $this->list($as, ['trust' => ['0']]);
            $this->assertEqualsCanonicalizing([1, 2, 3, 6], array_column($new['rows'], 'id'));

            $held = $this->list($as, ['status' => ['silenced']]);
            $this->assertSame([5], array_column($held['rows'], 'id'));
        }
    }

    #[Test]
    public function other_staff_never_see_a_level_and_the_filters_are_ignored(): void
    {
        $body = $this->list(2, ['q' => 'regular']);
        $this->assertNull($body['rows'][0]['trust']);
        $this->assertNull($body['trustLevels']);

        $this->assertSame(26, $this->list(2, ['trust' => ['1']])['total'], 'ignored, not answered');
        $this->assertSame(26, $this->list(2, ['status' => ['silenced']])['total']);

        $page = json_decode((string) $this->send($this->request('GET', '/api/member-desk/members/4', ['authenticatedAs' => 2]))->getBody(), true);
        $this->assertNull($page['member']['trust']);
    }

    #[Test]
    public function levels_on_the_list_are_read_in_one_query_for_1_or_20_members(): void
    {
        $count = function (array $params): array {
            $queries = 0;
            $db = $this->app()->getContainer()->make(\Illuminate\Database\ConnectionInterface::class);
            $db->listen(function () use (&$queries) {
                $queries++;
            });
            $body = $this->list(1, $params + ['rowsOnly' => 1]);
            $db->getEventDispatcher()?->forget(\Illuminate\Database\Events\QueryExecuted::class);

            return [count($body['rows']), $queries];
        };

        // A first request does one-time work (session, last seen, assets): not counted.
        $this->list(1, ['rowsOnly' => 1]);
        [$one, $oneQueries] = $count(['q' => 'regular']);
        [$twenty, $twentyQueries] = $count(['trust' => ['2']]);

        $this->assertSame(1, $one);
        $this->assertSame(20, $twenty);
        $this->assertSame($oneQueries, $twentyQueries, 'the same number of queries for 1 member as for 20');
        $this->assertSame(2, $this->list(1, ['trust' => ['2'], 'rowsOnly' => 1])['rows'][0]['trust']['level']);
    }
}
