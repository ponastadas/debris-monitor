<?php

namespace App\Models;

use Database\Factories\TleRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['satellite_id', 'line1', 'line2', 'epoch_at', 'source', 'fetched_at', 'is_current'])]
class TleRecord extends Model
{
    /** @use HasFactory<TleRecordFactory> */
    use HasFactory;

    public function satellite(): BelongsTo
    {
        return $this->belongsTo(Satellite::class);
    }

    protected function casts(): array
    {
        return [
            'epoch_at' => 'datetime',
            'fetched_at' => 'datetime',
            'is_current' => 'boolean',
        ];
    }
}
