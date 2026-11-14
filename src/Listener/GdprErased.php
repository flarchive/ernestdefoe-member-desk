<?php

namespace ErnestDefoe\MemberDesk\Listener;

use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use ErnestDefoe\MemberDesk\Audit\Recorder;

/**
 * flarum/gdpr's erasure finished (it runs in a queued job). If Member Desk asked for it, mark that
 * log entry done; if it came from anywhere else (the member's own request, gdpr's admin page),
 * log it too, so the log has every erasure. Only registered when flarum/gdpr is on.
 */
class GdprErased
{
    public function __construct(protected Recorder $recorder)
    {
    }

    public function handle(\Flarum\Gdpr\Events\Erased $event): void
    {
        $userId = (int) $event->user->id;
        $action = $event->mode === 'deletion' ? 'delete' : 'anonymize';

        /** @var AuditEntry|null $ours */
        $ours = AuditEntry::query()->where('user_id', $userId)->where('action', $action)->where('status', 'queued')
            ->orderByDesc('id')->first();

        if ($ours) {
            $ours->status = 'done';
            $ours->after = ($ours->after ?? []) + ['erasedAs' => $event->mode === 'deletion' ? null : $event->user->username];
            $ours->save();

            return;
        }

        $actor = $event->request?->processedBy;

        $entry = $this->recorder->record(
            $actor,
            $action,
            $userId,
            [
                'id' => $userId,
                'username' => $event->username,
                'emailHash' => $this->recorder->hash($event->email),
            ],
            ['via' => 'gdpr', 'source' => $actor ? 'staff' : 'member request'],
            null,
            'done',
            true
        );
        $this->recorder->scrubEarlier($userId, $entry->id);
    }
}
