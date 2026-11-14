<?php

/*
 * Member Desk: an Invision-style members area. In the admin it takes over the Users page; staff
 * with `ernestdefoe-member-desk.view` (moderators by default) get the same list and member page
 * on the forum at /member-desk, since Flarum's admin is admins-only.
 *
 * The API is plain JSON under /api/member-desk (src/Api/Controller.php). Permissions and the
 * hard rules (never yourself, never an admin) are in src/Access.php; every action runs through
 * Flarum's own API as the staff member (src/Actions.php), and delete/anonymize go through
 * flarum/gdpr's own erasure when it's installed.
 *
 * 🚨 String literals only below, never a class constant read at load time: it autoloads during
 * boot and fatals every request while a deploy is swapping composer's autoload map.
 */

use ErnestDefoe\MemberDesk\Access;
use ErnestDefoe\MemberDesk\Api\Controller;
use ErnestDefoe\MemberDesk\Console\PruneCommand;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // Its styles ride in the lazy chunk (less/forum-chunk.less), so forum.css stays untouched.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->route('/member-desk', 'member-desk.index')
        ->route('/member-desk/log', 'member-desk.log')
        ->route('/member-desk/{id:\d+}', 'member-desk.member'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/member-desk/members', 'member-desk.list', Controller::class)
        ->get('/member-desk/members/{id:\d+}', 'member-desk.show', Controller::class)
        ->post('/member-desk/preview', 'member-desk.preview', Controller::class)
        ->post('/member-desk/run', 'member-desk.run', Controller::class)
        ->get('/member-desk/batches/{batch:[0-9a-f-]{36}}', 'member-desk.batch', Controller::class)
        ->get('/member-desk/export', 'member-desk.export', Controller::class)
        ->get('/member-desk/log', 'member-desk.log', Controller::class)
        ->post('/member-desk/hash-check', 'member-desk.hashCheck', Controller::class)
        ->post('/member-desk/ban-ip', 'member-desk.banIp', Controller::class)
        ->get('/member-desk/filters', 'member-desk.filters', Controller::class)
        ->post('/member-desk/filters', 'member-desk.createFilter', Controller::class)
        ->patch('/member-desk/filters/{id:\d+}', 'member-desk.updateFilter', Controller::class)
        ->delete('/member-desk/filters/{id:\d+}', 'member-desk.deleteFilter', Controller::class),

    // Only staff who can open Member Desk get this (permission checks, no queries).
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Arr::make('memberDesk')
                ->visible(fn ($forum, Context $context) => resolve(Access::class)->view($context->getActor()))
                ->get(fn ($forum, Context $context) => resolve(Access::class)->forActor($context->getActor())),
        ]),

    (new Extend\Settings())
        ->default('ernestdefoe-member-desk.retention_days', 90),

    (new Extend\Console())
        ->command(PruneCommand::class)
        ->schedule(PruneCommand::class, function (ScheduledEvent $event) {
            $event->daily()->withoutOverlapping();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-gdpr', fn () => [
            (new Extend\Event())->listen(\Flarum\Gdpr\Events\Erased::class, \ErnestDefoe\MemberDesk\Listener\GdprErased::class),
        ])
        ->whenExtensionEnabled('flarum-audit', fn () => [
            (new \Flarum\Audit\Extend\Audit())
                ->group('ernestdefoe-member-desk')
                ->register(
                    'member_desk.addGroup', 'member_desk.removeGroup', 'member_desk.suspend', 'member_desk.unsuspend',
                    'member_desk.confirm', 'member_desk.signOut', 'member_desk.spammer', 'member_desk.unspam',
                    'member_desk.anonymize', 'member_desk.delete', 'member_desk.export', 'member_desk.banIp', 'member_desk.hashCheck'
                ),
        ]),
];
