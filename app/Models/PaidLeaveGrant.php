<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PaidLeaveGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'grant_date',
        'days_granted',
        'fiscal_year_start',
        'expires_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'grant_date' => 'date',
            'fiscal_year_start' => 'date',
            'expires_at' => 'date',
            'days_granted' => 'decimal:1',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this grant is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->lt(Carbon::today());
    }

    /**
     * Check if this grant is active (not expired)
     */
    public function isActive(): bool
    {
        return !$this->isExpired();
    }
}
