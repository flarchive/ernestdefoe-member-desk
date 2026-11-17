<?php

namespace ErnestDefoe\MemberDesk\Audit;

use Carbon\Carbon;
use ErnestDefoe\MemberDesk\Optional;
use ErnestDefoe\MemberDesk\Presenter;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Writes Member Desk's log, and mirrors each entry into flarum/audit when it's installed.
 *
 * 🚨 Privacy: an erased member's entry never holds their email or IPs in clear, or the log would
 * undo the erasure the admin just ran. They're kept as HMAC-SHA256 hashes salted with a secret
 * only this forum has (`ernestdefoe-member-desk.salt`): an admin can check "was it this
 * address?" but can't read it back. `member-desk:prune` strips even that after the retention
 * period (default 90 days).
 */
class Recorder
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Optional $optional,
        protected ConnectionInterface $db,
        protected LoggerInterface $log,
    ) {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(?User $actor, string $action, ?int $userId, ?array $before = null, ?array $after = null, ?string $batch = null, string $status = 'done', bool $erased = false): AuditEntry
    {
        $entry = new AuditEntry();
        $entry->actor_id = $actor?->id ?: null;
        $entry->action = $action;
        $entry->user_id = $userId;
        $entry->batch = $batch;
        $entry->status = $status;
        $entry->before = $before;
        $entry->after = $after;
        $entry->erased = $erased;
        $entry->created_at = Carbon::now();
        $entry->save();
        if ($erased) {
            $this->keepHashes($entry);
        }

        $this->mirror($actor, $action, $userId, $batch);

        return $entry;
    }

    protected function mirror(?User $actor, string $action, ?int $userId, ?string $batch): void
    {
        if (! $this->optional->audit()) {
            return;
        }

        try {
            if ($actor) {
                \Flarum\Audit\AuditLogger::$actor = $actor;
            }
            \Flarum\Audit\AuditLogger::log('member_desk.'.$action, array_filter([
                'user_id' => $userId,
                'batch' => $batch,
            ]));
        } catch (\Throwable $e) {
            // The audit extension's own trouble never stops a Member Desk action, but it is logged.
            $this->log->warning('[member-desk] flarum/audit could not log member_desk.'.$action.': '.$e->getMessage());
        }
    }

    /**
     * An erased member's EARLIER entries (a suspension, a group change) may hold their name in a
     * snapshot; strip it so only the erasure entry keeps it, and only until the prune.
     */
    public function scrubEarlier(int $userId, int $exceptId): void
    {
        AuditEntry::query()->where('user_id', $userId)->where('id', '<>', $exceptId)->whereNotNull('before')
            ->chunkById(200, function ($entries) {
                foreach ($entries as $entry) {
                    /** @var AuditEntry $entry */
                    $before = $entry->before ?? [];
                    unset($before['username'], $before['displayName'], $before['emailHash'], $before['ipHashes']);
                    $entry->before = $before ?: null;
                    $entry->save();
                }
                $this->forgetHashes($entries->modelKeys());
            });
    }

    /** The erasure entry's hashes, one indexed row each, for hash-check's lookup. */
    protected function keepHashes(AuditEntry $entry): void
    {
        $hashes = array_merge([$entry->before['emailHash'] ?? null], (array) ($entry->before['ipHashes'] ?? []));
        $rows = array_map(fn (string $h) => ['audit_id' => $entry->id, 'hash' => $h], array_values(array_unique(array_filter($hashes, 'is_string'))));
        if ($rows) {
            $this->db->table('member_desk_audit_hashes')->insertOrIgnore($rows);
        }
    }

    /** @param int[] $entryIds */
    public function forgetHashes(array $entryIds): void
    {
        $this->db->table('member_desk_audit_hashes')->whereIn('audit_id', $entryIds)->delete();
    }

    /**
     * Ids of erasure entries that kept this email or IP's hash, newest first.
     *
     * @return int[]
     */
    public function entriesWithHash(string $value, int $limit): array
    {
        return $this->db->table('member_desk_audit_hashes')->where('hash', $this->hash($value))
            ->orderByDesc('audit_id')->limit($limit)->pluck('audit_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', strtolower(trim($value)), $this->salt());
    }

    /**
     * The forum's own secret. The install migration creates it; if it was ever removed or left
     * empty, a new one is made and saved here, so hashes are never salted with an empty string.
     */
    protected function salt(): string
    {
        $salt = (string) $this->settings->get('ernestdefoe-member-desk.salt');
        if ($salt === '') {
            $salt = Str::random(64);
            $this->settings->set('ernestdefoe-member-desk.salt', $salt);
        }

        return $salt;
    }

    /**
     * The member as they were, for the log. Erased members keep only what the scope allows:
     * names, groups, dates and counts in clear; email and IPs hashed.
     *
     * @return array<string, mixed>
     */
    public function snapshot(User $member, bool $forErasure): array
    {
        $groups = $member->relationLoaded('groups') ? $member->groups : $member->groups()->get();

        $snap = [
            'id' => $member->id,
            'username' => $member->username,
            'displayName' => $member->display_name,
            'groups' => $groups->map(fn ($g) => ['id' => $g->id, 'name' => $g->name_singular])->values()->all(),
            'joinedAt' => Presenter::date($member->joined_at),
            'lastSeenAt' => Presenter::date($member->last_seen_at),
            'posts' => (int) $member->comment_count,
            'discussions' => (int) $member->discussion_count,
            'emailConfirmed' => (bool) $member->is_email_confirmed,
        ];

        if ($forErasure) {
            $snap['emailHash'] = $this->hash((string) $member->email);
            $snap['ipHashes'] = array_map(fn ($ip) => $this->hash($ip), $this->ipsOf($member->id));
        }

        return $snap;
    }

    /** @return string[] */
    public function ipsOf(int $userId): array
    {
        $fromTokens = $this->db->table('access_tokens')->where('user_id', $userId)->whereNotNull('last_ip_address')
            ->distinct()->pluck('last_ip_address');
        $fromPosts = $this->db->table('posts')->where('user_id', $userId)->whereNotNull('ip_address')
            ->distinct()->limit(200)->pluck('ip_address');

        return $fromTokens->merge($fromPosts)->filter()->unique()->values()->all();
    }
}
