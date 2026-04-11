<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['api_key_id', 'endpoint', 'method', 'status_code', 'response_ms', 'ip'])]
class ApiUsage extends Model
{
    public const UPDATED_AT = null;

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }
}
