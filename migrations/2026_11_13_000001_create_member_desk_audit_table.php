<?php

/*
 * Every Member Desk action, written BEFORE it runs so "the member as they were" survives a
 * failed job. user_id has no foreign key on purpose: a deleted member's entries must stay.
 * Index names are left to Laravel (prefix_indexes), never hand-named.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('member_desk_audit')) {
            return;
        }

        $schema->create('member_desk_audit', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('action', 40);
            $table->unsignedInteger('user_id')->nullable();
            $table->string('batch', 36)->nullable();
            $table->string('status', 16)->default('done');
            // JSON: the member as they were (erased members: email + IPs as salted hashes only).
            $table->mediumText('before')->nullable();
            // JSON: what changed, or what a spammer action hid (so it can be restored).
            $table->mediumText('after')->nullable();
            $table->boolean('erased')->default(false);
            $table->dateTime('created_at');

            $table->index('user_id');
            $table->index('created_at');
            $table->index('action');
            $table->index('batch');

            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('member_desk_audit');
    },
];
