<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'day_type',
        'shift_code',
        'clock_in',
        'clock_out',
        'go_out_at',
        'return_at',
        'break_minutes',
        'work_minutes',
        'status',
        'absence_reason',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'break_minutes' => 'integer',
            'work_minutes' => 'integer',
        ];
    }

    /**
     * 時刻文字列をH:i形式で取得
     */
    public function getClockInTimeAttribute(): ?string
    {
        return $this->formatTimeAttribute($this->clock_in);
    }

    public function getClockOutTimeAttribute(): ?string
    {
        return $this->formatTimeAttribute($this->clock_out);
    }

    public function getGoOutTimeAttribute(): ?string
    {
        return $this->formatTimeAttribute($this->go_out_at);
    }

    public function getReturnTimeAttribute(): ?string
    {
        return $this->formatTimeAttribute($this->return_at);
    }

    protected function formatTimeAttribute(?string $time): ?string
    {
        if (empty($time)) {
            return null;
        }
        // H:i:ss or H:i 形式を H:i に変換
        return substr($time, 0, 5);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
