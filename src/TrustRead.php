<?php

namespace ErnestDefoe\MemberDesk;

use ErnestDefoe\TrustLevels\TrustState;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads Trust Levels' per-member state both ways: from its own trust_states table (TrustState,
 * Trust Levels 1.1+) when that model exists, else from the older users.trust_* columns. Only
 * called after Access::trust() said this viewer may see levels.
 */
class TrustRead
{
    protected function stateTable(): bool
    {
        return class_exists(TrustState::class);
    }

    /** @return string[] relations a member list eager-loads, so rows never query one by one */
    public function eager(): array
    {
        return $this->stateTable() ? ['trustState'] : [];
    }

    /**
     * @param Builder<User> $query
     * @param int[] $levels
     */
    public function whereLevel(Builder $query, array $levels): void
    {
        $this->stateTable() ? TrustState::whereLevel($query, $levels) : $query->whereIn('trust_level', $levels);
    }

    /** @param Builder<User> $query */
    public function whereSilenced(Builder $query): void
    {
        $this->stateTable() ? TrustState::whereSilenced($query) : $query->whereNotNull('trust_silenced_at');
    }

    /** @return array{level: int, locked: bool, silenced: bool} */
    public function of(User $m): array
    {
        if ($this->stateTable()) {
            $s = TrustState::of($m);

            return ['level' => (int) $s->level, 'locked' => (bool) $s->locked, 'silenced' => $s->silenced_at !== null];
        }

        return [
            'level' => (int) $m->getAttribute('trust_level'),
            'locked' => (bool) $m->getAttribute('trust_locked'),
            'silenced' => (bool) $m->getAttribute('trust_silenced_at'),
        ];
    }
}
