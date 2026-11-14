<?php

namespace ErnestDefoe\MemberDesk\Audit;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property int|null $user_id
 * @property string|null $batch
 * @property string $status
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property bool $erased
 * @property Carbon $created_at
 */
class AuditEntry extends AbstractModel
{
    protected $table = 'member_desk_audit';

    public $timestamps = false;

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'erased' => 'boolean',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
