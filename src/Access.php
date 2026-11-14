<?php

namespace ErnestDefoe\MemberDesk;

use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * Who may do what in Member Desk. One place, used by every controller and by the forum payload.
 *
 * Hard rules on top of permissions: nobody acts on their own account through Member Desk's
 * destructive actions, and no admin can be suspended, marked as a spammer, anonymized or
 * deleted here (an admin is demoted first, on purpose, by another admin).
 */
class Access
{
    public const ACTIONS = [
        'addGroup', 'removeGroup', 'suspend', 'unsuspend', 'confirm', 'signOut',
        'spammer', 'unspam', 'anonymize', 'delete',
    ];

    /** Actions that may never touch the actor themselves or an admin. */
    public const PROTECTED = ['suspend', 'spammer', 'anonymize', 'delete', 'signOut'];

    public function __construct(protected Optional $optional)
    {
    }

    public function view(User $actor): bool
    {
        return $actor->isAdmin() || $actor->hasPermission('ernestdefoe-member-desk.view');
    }

    public function assertView(User $actor): void
    {
        if (! $this->view($actor)) {
            throw new PermissionDeniedException();
        }
    }

    public function emails(User $actor): bool
    {
        return $actor->isAdmin() || $actor->hasPermission('ernestdefoe-member-desk.viewEmails');
    }

    public function ips(User $actor): bool
    {
        return $actor->isAdmin() || $actor->hasPermission('ernestdefoe-member-desk.viewIps');
    }

    public function export(User $actor): bool
    {
        // A CSV carries emails, so exporting needs both.
        return $actor->isAdmin() || ($actor->hasPermission('ernestdefoe-member-desk.export') && $this->emails($actor));
    }

    /** Whether the actor's last-seen view applies to members who hide their online status. */
    public function lastSeen(User $actor): bool
    {
        return $actor->isAdmin() || $actor->hasPermission('user.viewLastSeenAt');
    }

    /**
     * May the actor run this action at all (before looking at any one member)?
     */
    public function action(User $actor, string $action): bool
    {
        if (! $this->view($actor)) {
            return false;
        }

        return match ($action) {
            'addGroup', 'removeGroup' => $actor->hasPermission('user.editGroups'),
            'suspend', 'unsuspend' => $this->optional->suspend() && $actor->hasPermission('user.suspend'),
            'confirm' => $actor->hasPermission('user.editCredentials'),
            'signOut' => $actor->hasPermission('user.editCredentials'),
            'spammer', 'unspam' => $this->optional->suspend()
                && ($actor->isAdmin() || $actor->hasPermission('ernestdefoe-member-desk.markSpammer')),
            'anonymize' => $actor->isAdmin() && $this->optional->gdpr() && $this->gdprAllows('anonymization'),
            'delete' => $actor->isAdmin() && (! $this->optional->gdpr() || $this->gdprAllows('deletion')),
            default => false,
        };
    }

    /**
     * Why this member is skipped for this action, or null when they aren't. $groupId is the group
     * being added or removed.
     */
    public function skipReason(User $actor, User $member, string $action, ?int $groupId = null): ?string
    {
        if (in_array($action, self::PROTECTED, true)) {
            if ($member->id === $actor->id) {
                return 'self';
            }
            if ($this->isAdmin($member)) {
                return 'admin';
            }
        }

        if ($this->optional->gdpr() && $member->getAttribute('anonymized') && $action !== 'removeGroup') {
            return 'anonymized';
        }

        if (($action === 'addGroup' || $action === 'removeGroup') && $groupId === \Flarum\Group\Group::ADMINISTRATOR_ID && ! $actor->isAdmin()) {
            return 'admin';
        }

        if ($action === 'removeGroup' && $groupId === \Flarum\Group\Group::ADMINISTRATOR_ID && $member->id === $actor->id) {
            return 'self';
        }

        return null;
    }

    /** isAdmin() without lazy-loading groups when they're already eager-loaded. */
    public function isAdmin(User $member): bool
    {
        if ($member->relationLoaded('groups')) {
            return $member->groups->contains('id', \Flarum\Group\Group::ADMINISTRATOR_ID);
        }

        return $member->isAdmin();
    }

    /**
     * flarum/gdpr's endpoint never checks its own settings; its queued job does, and fails there.
     * So only modes gdpr's settings allow are ever offered.
     */
    public function gdprAllows(string $mode): bool
    {
        $settings = resolve(\Flarum\Settings\SettingsRepositoryInterface::class);
        $key = $mode === 'deletion' ? 'flarum-gdpr.allow-deletion' : 'flarum-gdpr.allow-anonymization';
        $value = $settings->get($key);

        // Unset = gdpr's defaults: anonymization on, deletion off.
        if ($value === null) {
            return $mode !== 'deletion';
        }

        return (bool) $value; // '0' and '' are false too
    }

    /** @return array<string, bool> */
    public function forActor(User $actor): array
    {
        $out = [
            'view' => $this->view($actor),
            'emails' => $this->emails($actor),
            'ips' => $this->ips($actor),
            'export' => $this->export($actor),
            'isAdmin' => $actor->isAdmin(),
        ];

        foreach (self::ACTIONS as $action) {
            $out[$action] = $this->action($actor, $action);
        }

        return $out;
    }
}
