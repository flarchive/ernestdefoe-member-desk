<?php

namespace ErnestDefoe\MemberDesk;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property bool $shared
 * @property string $query
 * @property int $position
 * @property Carbon $created_at
 */
class SavedFilter extends AbstractModel
{
    protected $table = 'member_desk_filters';

    public $timestamps = false;

    protected $casts = [
        'shared' => 'boolean',
        'position' => 'integer',
        'created_at' => 'datetime',
    ];

    /** Its owner, or any admin when it's shared. */
    public function canEdit(User $actor): bool
    {
        return $this->user_id === $actor->id || ($this->shared && $actor->isAdmin());
    }

    /** @return array<string, mixed> */
    public function decoded(): array
    {
        return json_decode($this->query, true) ?: [];
    }

    /** @return array<string, mixed> */
    public function present(User $actor): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'shared' => $this->shared,
            'mine' => $this->user_id === $actor->id,
            'canEdit' => $this->canEdit($actor),
            'query' => $this->decoded(),
        ];
    }
}
