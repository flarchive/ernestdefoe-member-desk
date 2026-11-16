<?php

namespace ErnestDefoe\MemberDesk\Api;

use ErnestDefoe\MemberDesk\Access;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/**
 * The forum payload's `memberDesk`: what the viewer may do in Member Desk. Only staff who can
 * open it get the field (permission checks, no queries).
 */
class ForumFields
{
    public function __construct(protected Access $access)
    {
    }

    /** @return Schema\Arr[] */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('memberDesk')
                ->visible(fn ($forum, Context $context) => $this->access->view($context->getActor()))
                ->get(fn ($forum, Context $context) => $this->access->forActor($context->getActor())),
        ];
    }
}
