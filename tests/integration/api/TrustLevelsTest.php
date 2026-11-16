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
 * 3 mod (Member Desk + trust-levels.manage), 4 at level 3, 5 at level 1 and on hold.
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
                ['id' => 4, 'username' => 'regular', 'email' => 'regular@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 3, 'trust_locked' => true],
                ['id' => 5, 'username' => 'held', 'email' => 'held@machine.local', 'is_email_confirmed' => 1, 'trust_level' => 1, 'trust_silenced_at' => Carbon::now()],
            ],
            Group::class => [['id' => 10, 'name_singular' => 'Helper', 'name_plural' => 'Helpers', 'is_hidden' => 0]],
            'group_user' => [['user_id' => 2, 'group_id' => 10], ['user_id' => 3, 'group_id' => Group::MODERATOR_ID]],
            'group_permission' => [
                ['permission' => 'ernestdefoe-member-desk.view', 'group_id' => 10],
                ['permission' => 'ernestdefoe-member-desk.view', 'group_id' => Group::MODERATOR_ID],
                ['permission' => 'ernestdefoe-trust-levels.manage', 'group_id' => Group::MODERATOR_ID],
            ],
        ]);
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

        $this->assertSame(5, $this->list(2, ['trust' => ['1']])['total'], 'ignored, not answered');
        $this->assertSame(5, $this->list(2, ['status' => ['silenced']])['total']);

        $page = json_decode((string) $this->send($this->request('GET', '/api/member-desk/members/4', ['authenticatedAs' => 2]))->getBody(), true);
        $this->assertNull($page['member']['trust']);
    }
}
