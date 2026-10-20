<?php

namespace Modules\Tenants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Authentication\Models\User;

/**
 * Append-only audit log for tenant subscription mutations.
 */
class TenantSubscriptionAudit extends Model
{
    public $timestamps = false;

    protected $table = 'tenant_subscription_audits';

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'actor_role',
        'operation',
        'source',
        'reason',
        'before_state',
        'after_state',
        'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Subscription audit records cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Subscription audit records cannot be deleted.');
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
