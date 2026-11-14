<?php

namespace ErnestDefoe\MemberDesk\Console;

use Carbon\Carbon;
use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Console\Command;

/**
 * Erased members' log entries keep names and hashed email/IPs for the retention period (default
 * 90 days); after that only the id, counts and the action stay. Scheduled daily.
 */
class PruneCommand extends Command
{
    protected $signature = 'member-desk:prune';

    protected $description = 'Strip names and hashes from erased members\' Member Desk log entries past the retention period.';

    public function handle(SettingsRepositoryInterface $settings): int
    {
        $days = max(1, (int) ($settings->get('ernestdefoe-member-desk.retention_days') ?: 90));
        $cut = Carbon::now()->subDays($days);
        $pruned = 0;

        AuditEntry::query()->where('erased', true)->where('created_at', '<', $cut)
            ->whereNotNull('before')
            ->chunkById(200, function ($entries) use (&$pruned) {
                foreach ($entries as $entry) {
                    /** @var AuditEntry $entry */
                    $before = $entry->before ?? [];
                    if (! isset($before['username']) && ! isset($before['emailHash']) && ! isset($before['displayName'])) {
                        continue;
                    }
                    $entry->before = array_intersect_key($before, array_flip(['id', 'posts', 'discussions', 'pruned'])) + ['pruned' => true];
                    $entry->save();
                    $pruned++;
                }
            });

        $this->info("Pruned $pruned entries older than $days days.");

        return 0;
    }
}
