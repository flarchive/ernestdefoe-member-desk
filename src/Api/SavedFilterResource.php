<?php

namespace ErnestDefoe\MemberDesk\Api;

use Carbon\Carbon;
use ErnestDefoe\MemberDesk\Access;
use ErnestDefoe\MemberDesk\SavedFilter;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * Saved filters, as JSON:API type `member-desk-filters` (/api/member-desk-filters).
 *
 * Staff see their own filters and the shared ones. Only admins share a filter with every staff
 * member, and a filter is changed or deleted by its owner (or, when shared, by an admin).
 *
 * @extends AbstractDatabaseResource<SavedFilter>
 */
class SavedFilterResource extends AbstractDatabaseResource
{
    /** What a filter may remember: the list's own filter and sort keys, nothing else. */
    public const KEYS = ['q', 'groups', 'status', 'joinedFrom', 'joinedTo', 'seenFrom', 'seenTo', 'joinedWithin', 'notSeenFor', 'minPosts', 'maxPosts', 'reportedMin', 'reportedWithin', 'trust', 'sort', 'dir'];

    // Built on every API request: constructors only, no queries or settings reads.
    public function __construct(protected Access $access)
    {
    }

    public function type(): string
    {
        return 'member-desk-filters';
    }

    public function model(): string
    {
        return SavedFilter::class;
    }

    /** @param Builder<SavedFilter> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        $actor = $context->getActor();
        if (! $this->access->view($actor)) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->where(fn (Builder $w) => $w->where('shared', true)->orWhere('user_id', $actor->id));
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()->authenticated()->defaultSort('position'),
            Endpoint\Create::make()->authenticated(),
            Endpoint\Update::make()->authenticated(),
            Endpoint\Delete::make()->authenticated(),
        ];
    }

    public function creating(object $model, BaseContext $context): ?object
    {
        /** @var SavedFilter $model */
        $this->access->assertView($context->getActor());
        $model->user_id = $context->getActor()->id;
        $model->position = (int) SavedFilter::query()->max('position') + 1;
        $model->created_at = Carbon::now();

        return $model;
    }

    public function updating(object $model, BaseContext $context): ?object
    {
        /** @var SavedFilter $model */
        $this->assertCanEdit($model, $context->getActor());

        return $model;
    }

    public function deleting(object $model, BaseContext $context): void
    {
        /** @var SavedFilter $model */
        $this->assertCanEdit($model, $context->getActor());
    }

    protected function assertCanEdit(SavedFilter $filter, User $actor): void
    {
        if (! $filter->canEdit($actor)) {
            throw new PermissionDeniedException();
        }
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->writable()
                ->requiredOnCreate()
                ->maxLength(80)
                ->rule('regex:/\S/')
                ->set(fn (SavedFilter $f, string $value) => $f->name = trim($value)),
            // Only admins share filters with every staff member.
            Schema\Boolean::make('shared')
                ->writable()
                ->set(fn (SavedFilter $f, bool $value, Context $context) => $f->shared = $value && $context->getActor()->isAdmin()),
            Schema\Arr::make('query')
                ->writable()
                ->get(fn (SavedFilter $f) => $f->decoded())
                ->set(fn (SavedFilter $f, array $value) => $f->query = json_encode(Arr::only($value, self::KEYS)) ?: '{}'),
            Schema\Integer::make('position')
                ->writable()
                ->min(0),
            Schema\Boolean::make('mine')
                ->get(fn (SavedFilter $f, Context $context) => $f->user_id === $context->getActor()->id),
            Schema\Boolean::make('canEdit')
                ->get(fn (SavedFilter $f, Context $context) => $f->canEdit($context->getActor())),
        ];
    }

    public function sorts(): array
    {
        return [SortColumn::make('position')];
    }
}
