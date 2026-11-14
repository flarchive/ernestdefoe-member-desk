<?php

/*
 * Saved filters: private to their owner, or shared with every staff member who can open Member Desk.
 */

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('member_desk_filters')) {
            return;
        }

        $schema->create('member_desk_filters', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('name', 80);
            $table->boolean('shared')->default(false);
            $table->text('query');
            $table->unsignedInteger('position')->default(0);
            $table->dateTime('created_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('member_desk_filters');
    },
];
