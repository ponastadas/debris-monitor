<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'status',
        'description',
        'stripe_charge_id',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'refunded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Amount formatted as dollars (e.g. 2900 → "$29.00") */
    public function formattedAmount(): string
    {
        return '$'.number_format($this->amount / 100, 2);
    }
}
