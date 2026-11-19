<?php

namespace ErnestDefoe\MemberDesk;

use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use Flarum\Group\Group;
use Flarum\User\User;

/**
 * How a log entry looks to one staff member. 🚨 The stored snapshot holds more than every viewer
 * may see, so it is filtered by the VIEWER's own permissions, the same rules the list follows:
 * - hashes never leave the server (Hash check answers "was it this address?");
 * - a member's last-seen time only for staff with user.viewLastSeenAt (or admins);
 * - hidden groups only for staff with viewHiddenGroups (names in snapshots, and the group an
 *   add/remove changed);
 * - a suspension's staff reason only for staff who may suspend.
 */
class Log
{
    /** @var int[]|null */
    protected ?array $hidden = null;

    public function __construct(protected Access $access)
    {
    }

    /** @return array<string, mixed> */
    public function present(AuditEntry $e, User $viewer): array
    {
        $before = $e->before ?? [];
        $after = $e->after ?? [];
        unset($before['emailHash'], $before['ipHashes'], $after['ipHash']);

        if (! $this->access->lastSeen($viewer)) {
            unset($before['lastSeenAt'], $after['lastSeenAt']);
        }

        if (! $viewer->can('viewHiddenGroups')) {
            $before = $this->withoutHidden($before);
            $after = $this->withoutHidden($after);
            if (isset($after['group']) && in_array((int) $after['group'], $this->hiddenGroupIds(), true)) {
                $after['group'] = null;
            }
        }

        if (! $viewer->isAdmin() && ! $viewer->hasPermission('user.suspend')) {
            unset($after['reason']);
        }

        if ($e->action === 'spammer') {
            $after = [
                'posts' => count($after['posts'] ?? []),
                'discussions' => count($after['discussions'] ?? []),
                'bannedIps' => count($after['bannedIps'] ?? []),
                'restored' => ! empty($after['restored']),
            ];
        }

        return [
            'id' => $e->id,
            'action' => $e->action,
            'status' => $e->status,
            'batch' => $e->batch,
            'userId' => $e->user_id,
            'member' => $e->relationLoaded('member') && $e->member
                ? ['id' => $e->member->id, 'displayName' => $e->member->display_name, 'username' => $e->member->username, 'avatarUrl' => $e->member->avatar_url]
                : null,
            'actor' => $e->actor ? ['id' => $e->actor->id, 'displayName' => $e->actor->display_name, 'username' => $e->actor->username] : null,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'erased' => $e->erased,
            'hashesKept' => ! empty($e->before['emailHash']),
            'createdAt' => $e->created_at->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $side
     * @return array<string, mixed>
     */
    protected function withoutHidden(array $side): array
    {
        if (isset($side['groups']) && is_array($side['groups'])) {
            $hidden = $this->hiddenGroupIds();
            $side['groups'] = array_values(array_filter($side['groups'], fn ($g) => ! in_array((int) ($g['id'] ?? 0), $hidden, true)));
        }

        return $side;
    }

    /** @return int[] */
    protected function hiddenGroupIds(): array
    {
        return $this->hidden ??= Group::query()->where('is_hidden', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
