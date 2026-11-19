<?php

namespace ErnestDefoe\MemberDesk\Api;

use ErnestDefoe\MemberDesk\Access;
use ErnestDefoe\MemberDesk\Audit\AuditEntry;
use ErnestDefoe\MemberDesk\Log;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;
use Tobyz\JsonApiServer\Pagination\Pagination;
use WeakMap;

/**
 * Member Desk's log, read-only, as JSON:API type `member-desk-log` (/api/member-desk-log),
 * newest first, 50 a page. Filters: filter[action], filter[actor], filter[user].
 *
 * 🚨 Each entry is shaped for the VIEWER by Log::present() (hashes never leave the server;
 * last seen, hidden groups and suspension reasons follow the viewer's own permissions), so
 * every field reads from that one filtered copy, never from the stored snapshot.
 *
 * @extends AbstractDatabaseResource<AuditEntry>
 */
class LogResource extends AbstractDatabaseResource
{
    /** @var WeakMap<AuditEntry, array<string, mixed>> */
    protected WeakMap $shaped;

    // Built on every API request: constructors only, no queries or settings reads.
    public function __construct(protected Access $access, protected Log $log)
    {
        $this->shaped = new WeakMap();
    }

    public function type(): string
    {
        return 'member-desk-log';
    }

    public function model(): string
    {
        return AuditEntry::class;
    }

    /** @param Builder<AuditEntry> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        if (! $this->access->view($context->getActor())) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->with(['actor', 'member']);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->paginate(50, 50)
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters) => $this->listing($query, $pagination, $context, $filters)),
        ];
    }

    /**
     * @param Builder<AuditEntry> $query
     * @param array<string, mixed> $filters
     */
    protected function listing(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        if (! empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }
        if (! empty($filters['actor'])) {
            $query->where('actor_id', (int) $filters['actor']);
        }
        if (! empty($filters['user'])) {
            $query->where('user_id', (int) $filters['user']);
        }
        $query->orderByDesc('id');
        if ($pagination instanceof OffsetPagination) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }

    /** @return array<string, mixed> */
    protected function shaped(AuditEntry $e, User $viewer): array
    {
        return $this->shaped[$e] ??= $this->log->present($e, $viewer);
    }

    public function fields(): array
    {
        $field = fn (string $key) => fn (AuditEntry $e, Context $context) => $this->shaped($e, $context->getActor())[$key];

        return [
            Schema\Str::make('action')->get($field('action')),
            Schema\Str::make('status')->get($field('status')),
            Schema\Str::make('batch')->nullable()->get($field('batch')),
            Schema\Integer::make('userId')->nullable()->get($field('userId')),
            Schema\Arr::make('member')->nullable()->get($field('member')),
            Schema\Arr::make('actor')->nullable()->get($field('actor')),
            Schema\Arr::make('before')->nullable()->get($field('before')),
            Schema\Arr::make('after')->nullable()->get($field('after')),
            Schema\Boolean::make('erased')->get($field('erased')),
            Schema\Boolean::make('hashesKept')->get($field('hashesKept')),
            Schema\Str::make('createdAt')->get($field('createdAt')),
        ];
    }
}
