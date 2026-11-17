<?php

namespace ErnestDefoe\MemberDesk;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Bans and unbans through fof/ban-ips' own API (its BannedIPResource), never by writing its table:
 * ban-ips stays the source of truth, with its permission check (`banIP` on the member), its
 * validation (a real, unique IP) and anything that hooks its bans. Member Desk's own guards sit on
 * top: never the acting staff member's own address, and never an admin.
 */
class BanIps
{
    public function __construct(
        protected ApiCall $call,
        protected Optional $optional,
        protected ConnectionInterface $db,
    ) {
    }

    /** Ban one address. Returns the ban's id, or null when ban-ips refused it. */
    public function ban(User $actor, string $ip, ?int $userId, string $reason): ?int
    {
        if (! $this->optional->banIps()) {
            return null;
        }

        $attributes = ['address' => $ip, 'reason' => $reason];
        if ($userId) {
            $attributes['userId'] = $userId;
        }

        $response = $this->call->send($actor, \FoF\BanIPs\Api\Resource\BannedIPResource::class, 'create', 'POST', '/banned_ips', [
            'data' => ['type' => 'banned_ips', 'attributes' => $attributes],
        ]);

        $body = json_decode((string) $response->getBody(), true);

        return isset($body['data']['id']) ? (int) $body['data']['id'] : null;
    }

    /** Lift the bans on these addresses that were placed for this member. */
    public function unban(User $actor, array $ips, int $userId): void
    {
        if (! $this->optional->banIps() || ! $ips) {
            return;
        }

        $ids = $this->db->table('banned_ips')->whereIn('address', $ips)->where('user_id', $userId)->pluck('id');
        foreach ($ids as $id) {
            $this->call->send($actor, \FoF\BanIPs\Api\Resource\BannedIPResource::class, 'delete', 'DELETE', '/banned_ips/'.$id);
        }
    }

    public function actorUses(User $actor, string $ip): bool
    {
        return $this->db->table('access_tokens')->where('user_id', $actor->id)->where('last_ip_address', $ip)->exists();
    }
}
