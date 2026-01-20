<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'normalized_name',
        'card_name',
        'normalized_card_name',
        'email',
        'password',
        'employee_code',
        'card_number',
        'department_id',
        'position',
        'employment_type',
        'joined_at',
        'leave_grant_date',
        'leave_grant_month',
        'initial_leave_balance',
        'initial_leave_date',
        'initial_leave_imported',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'joined_at' => 'date',
            'leave_grant_date' => 'date',
            'leave_grant_month' => 'integer',
            'initial_leave_balance' => 'decimal:1',
            'initial_leave_date' => 'date',
            'initial_leave_imported' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Normalize a name for matching purposes
     * - Convert half-width kana to full-width kana
     * - Remove all spaces (full-width and half-width)
     */
    public static function normalizeName(string $name): string
    {
        // Convert half-width kana to full-width kana (KV option)
        // Also convert half-width alphanumeric to full-width (A option)
        $normalized = mb_convert_kana($name, 'KVas');

        // Remove all spaces (full-width and half-width)
        $normalized = str_replace([' ', '　'], '', $normalized);

        return $normalized;
    }

    /**
     * Set the normalized_name when setting name
     */
    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = $value;
        $this->attributes['normalized_name'] = self::normalizeName($value);
    }

    /**
     * Set the normalized_card_name when setting card_name
     */
    public function setCardNameAttribute(?string $value): void
    {
        $this->attributes['card_name'] = $value;
        $this->attributes['normalized_card_name'] = $value ? self::normalizeName($value) : null;
    }

    /**
     * Find user by name with priority matching:
     * 1. normalized_name (氏名)
     * 2. normalized_card_name (タイムカード名)
     * 3. card_number (カード番号)
     */
    public static function findByNameOrCard(string $name): ?self
    {
        $normalizedInput = self::normalizeName($name);

        // 1. Search by normalized_name
        $user = self::where('normalized_name', $normalizedInput)->first();
        if ($user) {
            return $user;
        }

        // 2. Search by normalized_card_name
        $user = self::where('normalized_card_name', $normalizedInput)->first();
        if ($user) {
            return $user;
        }

        // 3. Search by card_number (exact match)
        $user = self::where('card_number', $name)->first();
        if ($user) {
            return $user;
        }

        return null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function paidLeaveGrants(): HasMany
    {
        return $this->hasMany(PaidLeaveGrant::class);
    }

    public function paidLeaveUsages(): HasMany
    {
        return $this->hasMany(PaidLeaveUsage::class);
    }

    /**
     * Get the effective leave grant date (manual override or calculated from hire date)
     * Default: 6 months after hire date, adjusted to 1st of that month
     */
    public function getEffectiveLeaveGrantDateAttribute(): ?Carbon
    {
        if ($this->leave_grant_date) {
            return $this->leave_grant_date;
        }

        if (!$this->joined_at) {
            return null;
        }

        // 6 months after hire, adjusted to 1st of the month
        return Carbon::parse($this->joined_at)->addMonths(6)->startOfMonth();
    }
}
