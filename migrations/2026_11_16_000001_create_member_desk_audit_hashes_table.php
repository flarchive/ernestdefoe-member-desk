<?php

/*
 * The salted hashes kept for an erased member (email + IPs), one row each, so "was it this
 * email/IP?" is an indexed lookup instead of reading every erasure entry's JSON. The JSON copy in
 * member_desk_audit.before stays (the log shows "hashes kept" from it); both are stripped together
 * by the prune. Backfilled here from existing entries, 200 at a time. Index names are Laravel's.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('member_desk_audit_hashes')) {
            $schema->create('member_desk_audit_hashes', function (Blueprint $table) {
                $table->unsignedInteger('audit_id');
                $table->char('hash', 64);

                $table->primary(['audit_id', 'hash']);
                $table->index('hash');
                $table->foreign('audit_id')->references('id')->on('member_desk_audit')->cascadeOnDelete();
            });
        }

        $db = $schema->getConnection();
        $db->table('member_desk_audit')->where('erased', true)->whereNotNull('before')->orderBy('id')
            ->chunkById(200, function ($entries) use ($db) {
                $rows = [];
                foreach ($entries as $entry) {
                    $before = json_decode((string) $entry->before, true) ?: [];
                    $hashes = array_merge([$before['emailHash'] ?? null], (array) ($before['ipHashes'] ?? []));
                    foreach (array_unique(array_filter($hashes, 'is_string')) as $hash) {
                        $rows[] = ['audit_id' => $entry->id, 'hash' => $hash];
                    }
                }
                if ($rows) {
                    $db->table('member_desk_audit_hashes')->insertOrIgnore($rows);
                }
            });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('member_desk_audit_hashes');
    },
];
