<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    protected $table = 'admin_audit_logs';

    public $timestamps = false; // only created_at, set by DB default

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminAccount::class, 'admin_account_id');
    }

    /**
     * Write a single immutable audit entry.
     */
    public static function record(
        int $adminId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $metadata = [],
    ): void {
        static::create([
            'admin_account_id' => $adminId,
            'action'           => $action,
            'target_type'      => $targetType,
            'target_id'        => $targetId,
            'metadata'         => $metadata ?: null,
            'ip'               => request()->ip(),
        ]);
    }
}
