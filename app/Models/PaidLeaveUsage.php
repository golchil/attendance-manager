<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaidLeaveUsage extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'leave_type',
        'days',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'days' => 'decimal:1',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get display label for leave type
     */
    public function getLeaveTypeLabelAttribute(): string
    {
        return match ($this->leave_type) {
            'paid_leave' => '全休',
            'am_half_leave' => '午前半休',
            'pm_half_leave' => '午後半休',
            default => $this->leave_type,
        };
    }
}
