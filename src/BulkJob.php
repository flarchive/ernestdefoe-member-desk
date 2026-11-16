<?php

namespace ErnestDefoe\MemberDesk;

use Flarum\Queue\AbstractJob;
use Flarum\User\User;

/**
 * A bulk action on more than Bulk::INLINE members, run as the staff member who confirmed it,
 * 100 at a time, with progress written to the cache after each chunk.
 */
class BulkJob extends AbstractJob
{
    /**
     * @param int[] $ids
     * @param array<string, mixed> $params
     */
    public function __construct(
        public int $actorId,
        public string $action,
        public array $ids,
        public array $params,
        public string $batch,
    ) {
        parent::__construct();
    }

    public function handle(Bulk $bulk): void
    {
        $actor = User::query()->find($this->actorId);
        if (! $actor) {
            return;
        }

        $done = 0;
        $failed = [];
        $total = count($this->ids);

        foreach (array_chunk($this->ids, 100) as $chunk) {
            $result = $bulk->runIds($actor, $this->action, $chunk, $this->params, $this->batch);
            $done += $result['done'];
            $failed = array_merge($failed, $result['failed']);
            $bulk->progress($this->batch, ['total' => $total, 'done' => $done, 'failed' => array_slice($failed, 0, 200), 'failedCount' => count($failed), 'finished' => false, 'actor' => $actor->id]);
        }

        $bulk->progress($this->batch, ['total' => $total, 'done' => $done, 'failed' => array_slice($failed, 0, 200), 'failedCount' => count($failed), 'finished' => true, 'actor' => $actor->id]);
    }
}
