<?php

/*
 * A secret only this forum knows, used to salt the hashes kept for erased members (HMAC-SHA256),
 * and three starter saved filters.
 *
 * No permissions are granted: Member Desk starts ADMIN-ONLY (Ernest, 2026-11-14). It shows
 * members' emails, IPs and history, so a forum owner opts moderators in on the Permissions page.
 */

use Carbon\Carbon;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Str;

return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();

        if (! $db->table('settings')->where('key', 'ernestdefoe-member-desk.salt')->exists()) {
            $db->table('settings')->insert(['key' => 'ernestdefoe-member-desk.salt', 'value' => Str::random(64)]);
        }

        if (! $db->table('member_desk_filters')->exists()) {
            $now = Carbon::now();
            $db->table('member_desk_filters')->insert([
                ['user_id' => null, 'name' => 'New this week', 'shared' => true, 'position' => 0, 'created_at' => $now,
                    'query' => json_encode(['joinedWithin' => 'P7D'])],
                ['user_id' => null, 'name' => 'Spam check', 'shared' => true, 'position' => 1, 'created_at' => $now,
                    'query' => json_encode(['status' => ['unconfirmed', 'noposts'], 'joinedWithin' => 'P14D'])],
                ['user_id' => null, 'name' => 'Inactive 2 years', 'shared' => true, 'position' => 2, 'created_at' => $now,
                    'query' => json_encode(['notSeenFor' => 'P2Y'])],
            ]);
        }
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();
        $db->table('settings')->where('key', 'like', 'ernestdefoe-member-desk.%')->delete();
        $db->table('group_permission')->where('permission', 'like', 'ernestdefoe-member-desk.%')->delete();
    },
];
