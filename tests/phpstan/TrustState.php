<?php

namespace ErnestDefoe\TrustLevels;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trust Levels 1.1's state model, for analysis against an older Trust Levels in vendor.
 *
 * @property int $level
 * @property bool $locked
 * @property \Carbon\Carbon|null $silenced_at
 */
class TrustState extends AbstractModel
{
    public static function of(User $user): self { return new self(); }

    /**
     * @param Builder<User> $users
     * @param int[] $levels
     * @return Builder<User>
     */
    public static function whereLevel(Builder $users, array $levels): Builder { return $users; }

    /**
     * @param Builder<User> $users
     * @return Builder<User>
     */
    public static function whereSilenced(Builder $users): Builder { return $users; }
}
