<?php

namespace App\Services;

use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveUsage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PaidLeaveService
{
    /**
     * Days granted based on tenure - New Rule (6-month base)
     * Key: months of service at grant time
     * Value: days to grant
     */
    protected const GRANT_TABLE_NEW = [
        6 => 10,    // 6 months
        18 => 11,   // 1.5 years
        30 => 12,   // 2.5 years
        42 => 14,   // 3.5 years
        54 => 16,   // 4.5 years
        66 => 18,   // 5.5 years
        78 => 20,   // 6.5+ years (max)
    ];

    /**
     * Days granted based on tenure - Old Rule (1-year base, for veteran employees)
     * Key: months of service at grant time
     * Value: days to grant
     */
    protected const GRANT_TABLE_OLD = [
        12 => 10,   // 1 year
        24 => 11,   // 2 years
        36 => 12,   // 3 years
        48 => 14,   // 4 years
        60 => 16,   // 5 years
        72 => 18,   // 6 years
        84 => 20,   // 7+ years (max)
    ];

    protected const MAX_CARRYOVER_DAYS = 20;  // Maximum carryover from previous year
    protected const MAX_TOTAL_DAYS = 40;      // Maximum balance (20 current + 20 carryover)
    protected const EXPIRATION_YEARS = 2;     // 2-year expiration

    /**
     * Calculate days to grant based on tenure
     */
    public function calculateGrantDays(User $user, Carbon $grantDate, bool $useOldRule = false): int
    {
        if (!$user->joined_at) {
            return 0;
        }

        $monthsOfService = $user->joined_at->diffInMonths($grantDate);

        $table = $useOldRule ? self::GRANT_TABLE_OLD : self::GRANT_TABLE_NEW;

        $days = 0;
        foreach ($table as $months => $grantDays) {
            if ($monthsOfService >= $months) {
                $days = $grantDays;
            }
        }

        return $days;
    }

    /**
     * Create a new paid leave grant for a user
     */
    public function createGrant(
        User $user,
        Carbon $grantDate,
        ?float $daysGranted = null,
        ?string $note = null,
        bool $useOldRule = false
    ): PaidLeaveGrant {
        $days = $daysGranted ?? $this->calculateGrantDays($user, $grantDate, $useOldRule);

        return PaidLeaveGrant::create([
            'user_id' => $user->id,
            'grant_date' => $grantDate,
            'days_granted' => $days,
            'fiscal_year_start' => $grantDate,
            'expires_at' => $grantDate->copy()->addYears(self::EXPIRATION_YEARS),
            'note' => $note,
        ]);
    }

    /**
     * Calculate leave usage from both paid_leave_usages and attendances tables
     * Avoids double-counting by tracking dates already counted
     */
    public function calculateUsage(User $user, ?Carbon $fromDate = null, ?Carbon $toDate = null): float
    {
        $totalUsage = 0.0;
        $countedDates = [];

        // 1. Get usage from paid_leave_usages table
        $usageQuery = PaidLeaveUsage::where('user_id', $user->id);
        if ($fromDate) {
            $usageQuery->where('date', '>=', $fromDate);
        }
        if ($toDate) {
            $usageQuery->where('date', '<=', $toDate);
        }

        $usages = $usageQuery->get();
        foreach ($usages as $usage) {
            $dateKey = $usage->date->format('Y-m-d') . '_' . $usage->leave_type;
            if (!isset($countedDates[$dateKey])) {
                $totalUsage += (float) $usage->days;
                $countedDates[$dateKey] = true;
            }
        }

        // 2. Get usage from attendances table (absence_reason based)
        $attendanceQuery = $user->attendances()
            ->whereIn('absence_reason', ['paid_leave', 'am_half_leave', 'pm_half_leave']);
        if ($fromDate) {
            $attendanceQuery->where('date', '>=', $fromDate);
        }
        if ($toDate) {
            $attendanceQuery->where('date', '<=', $toDate);
        }

        $attendances = $attendanceQuery->get();
        foreach ($attendances as $attendance) {
            $dateKey = $attendance->date->format('Y-m-d') . '_' . $attendance->absence_reason;

            // Skip if already counted from paid_leave_usages
            if (isset($countedDates[$dateKey])) {
                continue;
            }

            // Calculate days based on absence_reason
            $days = match ($attendance->absence_reason) {
                'paid_leave' => 1.0,
                'am_half_leave', 'pm_half_leave' => 0.5,
                default => 0.0,
            };

            $totalUsage += $days;
            $countedDates[$dateKey] = true;
        }

        return $totalUsage;
    }

    /**
     * Calculate usage for a specific grant period
     */
    public function calculateUsageForGrant(PaidLeaveGrant $grant): float
    {
        return $this->calculateUsage(
            $grant->user,
            $grant->grant_date,
            $grant->expires_at
        );
    }

    /**
     * Get remaining balance for a specific grant
     */
    public function getGrantBalance(PaidLeaveGrant $grant): float
    {
        $usage = $this->calculateUsageForGrant($grant);
        return max(0, $grant->days_granted - $usage);
    }

    /**
     * Calculate total remaining leave balance for a user
     * Priority: 1. Initial balance if set, 2. Calculate from hire date
     */
    public function calculateBalance(User $user): array
    {
        // If initial balance is set, use the initial balance method
        if ($user->initial_leave_balance !== null) {
            return $this->calculateBalanceFromInitial($user);
        }

        // If joined_at is set, calculate from hire date
        if ($user->joined_at) {
            return $this->calculateBalanceFromHireDate($user);
        }

        // No data available
        return [
            'total_granted' => 0,
            'total_used' => 0,
            'total_remaining' => 0,
            'grants' => [],
            'is_at_max' => false,
        ];
    }

    /**
     * Calculate balance from initial balance (new method)
     * Uses same year-by-year calculation as getYearlySummary for consistency
     */
    protected function calculateBalanceFromInitial(User $user): array
    {
        $today = Carbon::today();
        $initialBalance = (float) $user->initial_leave_balance;
        $effectiveGrantDate = $user->effective_leave_grant_date;
        $useOldRule = $this->isUsingOldRule($user);

        if (!$effectiveGrantDate) {
            return [
                'total_granted' => $initialBalance,
                'total_used' => 0,
                'total_remaining' => $initialBalance,
                'grants' => [],
                'is_at_max' => $initialBalance >= self::MAX_TOTAL_DAYS,
            ];
        }

        // Use initial_leave_date if set, otherwise calculate from leave_grant_date (2019 version)
        $initialDate = $user->initial_leave_date;
        if (!$initialDate) {
            $initialDate = Carbon::create(2019, $effectiveGrantDate->month, $effectiveGrantDate->day)->startOfDay();
        }

        // Calculate current fiscal year start
        $currentFiscalYearStart = $effectiveGrantDate->copy();
        while ($currentFiscalYearStart->copy()->addYear()->lte($today)) {
            $currentFiscalYearStart->addYear();
        }

        // Build list of fiscal years from initial date to current
        $yearsList = [];
        $fiscalYearStart = $initialDate->copy();
        while ($fiscalYearStart->lte($currentFiscalYearStart)) {
            $yearsList[] = $fiscalYearStart->copy();
            $fiscalYearStart->addYear();
        }

        // Calculate year by year with proper carryover
        $previousRemaining = 0.0;
        $totalGranted = 0.0;
        $totalUsed = 0.0;
        $grants = [];

        foreach ($yearsList as $index => $yearStart) {
            $yearEnd = $yearStart->copy()->addYear()->subDay();
            $isCurrentYear = $yearStart->eq($currentFiscalYearStart);

            // Usage for this fiscal year (up to today for current year)
            $usageEndDate = $isCurrentYear ? $today : $yearEnd;
            $usage = $this->calculateUsage($user, $yearStart, $usageEndDate);

            // Determine granted days and carryover
            $granted = 0.0;
            $carryover = 0.0;

            if ($index === 0) {
                // Initial year - use initial_leave_balance
                $carryover = $initialBalance;
                $granted = 0.0;
            } else {
                // Subsequent years
                $granted = (float) $this->calculateGrantDays($user, $yearStart, $useOldRule);
                $carryover = min(self::MAX_CARRYOVER_DAYS, $previousRemaining);
            }

            $available = $carryover + $granted;
            $remaining = max(0, $available - $usage);
            $remaining = min(self::MAX_TOTAL_DAYS, $remaining);

            $totalGranted += ($index === 0) ? $carryover : $granted;
            $totalUsed += $usage;
            $previousRemaining = $remaining;

            // Track active grants for display
            if ($index > 0) {
                $expiresAt = $yearStart->copy()->addYears(self::EXPIRATION_YEARS);
                if ($expiresAt->gte($today)) {
                    $grants[] = [
                        'grant_date' => $yearStart->copy(),
                        'days_granted' => $granted,
                        'expires_at' => $expiresAt,
                        'is_expiring_soon' => $expiresAt->lte($today->copy()->addMonths(3)),
                    ];
                }
            }
        }

        return [
            'total_granted' => $totalGranted,
            'total_used' => $totalUsed,
            'total_remaining' => $previousRemaining,
            'grants' => $grants,
            'is_at_max' => $previousRemaining >= self::MAX_TOTAL_DAYS,
            'initial_balance' => $initialBalance,
            'initial_date' => $initialDate,
        ];
    }

    /**
     * Calculate balance from hire date (for users without initial balance)
     * First grant is 6 months (new rule) or 1 year (old rule) after hire
     */
    protected function calculateBalanceFromHireDate(User $user): array
    {
        $today = Carbon::today();
        $joinedAt = $user->joined_at;
        $useOldRule = $this->isUsingOldRule($user);

        // Calculate first grant date
        $firstGrantDate = $useOldRule
            ? $joinedAt->copy()->addYear()->startOfMonth()
            : $joinedAt->copy()->addMonths(6)->startOfMonth();

        // If first grant date is in the future, no grants yet
        if ($firstGrantDate->gt($today)) {
            return [
                'total_granted' => 0,
                'total_used' => 0,
                'total_remaining' => 0,
                'grants' => [],
                'is_at_max' => false,
            ];
        }

        // Use effective_leave_grant_date if set, otherwise use calculated first grant date
        $effectiveGrantDate = $user->effective_leave_grant_date ?? $firstGrantDate;

        // Calculate current fiscal year start
        $currentFiscalYearStart = $effectiveGrantDate->copy();
        while ($currentFiscalYearStart->copy()->addYear()->lte($today)) {
            $currentFiscalYearStart->addYear();
        }

        // Build list of fiscal years from first grant to current
        $yearsList = [];
        $fiscalYearStart = $firstGrantDate->copy();
        while ($fiscalYearStart->lte($currentFiscalYearStart)) {
            $yearsList[] = $fiscalYearStart->copy();
            $fiscalYearStart->addYear();
        }

        // Calculate year by year with proper carryover
        $previousRemaining = 0.0;
        $totalGranted = 0.0;
        $totalUsed = 0.0;
        $grants = [];

        foreach ($yearsList as $index => $yearStart) {
            $yearEnd = $yearStart->copy()->addYear()->subDay();
            $isCurrentYear = $yearStart->eq($currentFiscalYearStart);

            // Usage for this fiscal year (up to today for current year)
            $usageEndDate = $isCurrentYear ? $today : $yearEnd;
            $usage = $this->calculateUsage($user, $yearStart, $usageEndDate);

            // Calculate grant days for this year
            $granted = (float) $this->calculateGrantDays($user, $yearStart, $useOldRule);

            // Carryover from previous year (capped at 20)
            $carryover = min(self::MAX_CARRYOVER_DAYS, $previousRemaining);

            $available = $carryover + $granted;
            $remaining = max(0, $available - $usage);
            $remaining = min(self::MAX_TOTAL_DAYS, $remaining);

            $totalGranted += $granted;
            $totalUsed += $usage;
            $previousRemaining = $remaining;

            // Track active grants for display
            $expiresAt = $yearStart->copy()->addYears(self::EXPIRATION_YEARS);
            if ($expiresAt->gte($today)) {
                $grants[] = [
                    'grant_date' => $yearStart->copy(),
                    'days_granted' => $granted,
                    'expires_at' => $expiresAt,
                    'is_expiring_soon' => $expiresAt->lte($today->copy()->addMonths(3)),
                ];
            }
        }

        return [
            'total_granted' => $totalGranted,
            'total_used' => $totalUsed,
            'total_remaining' => $previousRemaining,
            'grants' => $grants,
            'is_at_max' => $previousRemaining >= self::MAX_TOTAL_DAYS,
        ];
    }

    /**
     * Calculate balance from paid_leave_grants (original method)
     */
    protected function calculateBalanceFromGrants(User $user): array
    {
        $today = Carbon::today();

        // Get all active grants (not expired), ordered by grant_date (oldest first for FIFO)
        $activeGrants = $user->paidLeaveGrants()
            ->where('expires_at', '>=', $today)
            ->orderBy('grant_date', 'asc')
            ->get();

        // Calculate total usage for the user (all time within active grant periods)
        $totalUsageCalculated = 0.0;
        $grantDetails = [];

        foreach ($activeGrants as $grant) {
            $grantDetails[] = [
                'grant' => $grant,
                'days_granted' => (float) $grant->days_granted,
                'expires_at' => $grant->expires_at,
                'is_expiring_soon' => $grant->expires_at->lte($today->copy()->addMonths(3)),
            ];
        }

        // Get usage from attendance for the entire period covered by active grants
        if ($activeGrants->isNotEmpty()) {
            $earliestGrantDate = $activeGrants->first()->grant_date;
            $latestExpiryDate = $activeGrants->max('expires_at');
            $totalUsageCalculated = $this->calculateUsage($user, $earliestGrantDate, $latestExpiryDate);
        }

        // Apply FIFO consumption to grants
        $remainingUsage = $totalUsageCalculated;
        $totalGranted = 0.0;

        foreach ($grantDetails as &$detail) {
            $totalGranted += $detail['days_granted'];

            // Consume from this grant
            $consumed = min($remainingUsage, $detail['days_granted']);
            $detail['days_used'] = $consumed;
            $detail['days_remaining'] = $detail['days_granted'] - $consumed;
            $remainingUsage -= $consumed;
        }
        unset($detail);

        $totalRemaining = max(0, $totalGranted - $totalUsageCalculated);
        // Cap at maximum allowed
        $totalRemaining = min(self::MAX_TOTAL_DAYS, $totalRemaining);

        return [
            'total_granted' => $totalGranted,
            'total_used' => $totalUsageCalculated,
            'total_remaining' => $totalRemaining,
            'grants' => $grantDetails,
            'is_at_max' => $totalRemaining >= self::MAX_TOTAL_DAYS,
        ];
    }

    /**
     * Get all active employees with their leave balance summary
     */
    public function getAllEmployeesBalance(): Collection
    {
        return User::where('is_active', true)
            ->with(['department', 'paidLeaveGrants'])
            ->get()
            ->map(function (User $user) {
                $balance = $this->calculateBalance($user);
                return [
                    'user' => $user,
                    'balance' => $balance,
                ];
            });
    }

    /**
     * Generate the next grant date for a user
     */
    public function getNextGrantDate(User $user): ?Carbon
    {
        $effectiveGrantDate = $user->effective_leave_grant_date;

        if (!$effectiveGrantDate) {
            return null;
        }

        $today = Carbon::today();
        $nextGrant = $effectiveGrantDate->copy();

        // Find the next occurrence of grant date
        while ($nextGrant->lte($today)) {
            $nextGrant->addYear();
        }

        return $nextGrant;
    }

    /**
     * Check if a user is eligible for their first grant
     */
    public function isEligibleForFirstGrant(User $user): bool
    {
        if (!$user->joined_at) {
            return false;
        }

        // Check if 6 months have passed since hire
        $sixMonthsAfterHire = $user->joined_at->copy()->addMonths(6);

        return Carbon::today()->gte($sixMonthsAfterHire) &&
               $user->paidLeaveGrants()->count() === 0;
    }

    /**
     * Determine if old rule should be used based on leave_grant_date vs calculated date
     */
    public function isUsingOldRule(User $user): bool
    {
        if (!$user->leave_grant_date || !$user->joined_at) {
            return false;
        }

        // If manual grant date is 1 year after hire (not 6 months), it's old rule
        $sixMonthsAfterHire = $user->joined_at->copy()->addMonths(6)->startOfMonth();
        $oneYearAfterHire = $user->joined_at->copy()->addYear();

        // Check if grant date is closer to 1 year than 6 months
        $diffToSixMonths = abs($user->leave_grant_date->diffInDays($sixMonthsAfterHire));
        $diffToOneYear = abs($user->leave_grant_date->diffInDays($oneYearAfterHire));

        return $diffToOneYear < $diffToSixMonths;
    }

    /**
     * Get yearly summary for leave management ledger (5 years)
     * Each fiscal year runs from grant_date to the day before next grant_date
     * Always calculates from initial year or hire date to ensure correct carryover
     */
    public function getYearlySummary(User $user, int $years = 5): array
    {
        $effectiveGrantDate = $user->effective_leave_grant_date;
        if (!$effectiveGrantDate) {
            return [];
        }

        $today = Carbon::today();
        $useOldRule = $this->isUsingOldRule($user);

        // Determine initial date and balance
        $hasInitialBalance = $user->initial_leave_balance !== null;
        $initialDate = $user->initial_leave_date;
        if ($hasInitialBalance && !$initialDate) {
            $initialDate = Carbon::create(2019, $effectiveGrantDate->month, $effectiveGrantDate->day)->startOfDay();
        }

        // For users without initial balance, calculate first grant date from hire date
        $firstGrantDate = null;
        if (!$hasInitialBalance && $user->joined_at) {
            $firstGrantDate = $useOldRule
                ? $user->joined_at->copy()->addYear()->startOfMonth()
                : $user->joined_at->copy()->addMonths(6)->startOfMonth();
        }

        // Calculate current fiscal year start
        $currentFiscalYearStart = $effectiveGrantDate->copy();
        while ($currentFiscalYearStart->copy()->addYear()->lte($today)) {
            $currentFiscalYearStart->addYear();
        }

        // Determine the earliest year to display
        $displayStartYear = $currentFiscalYearStart->copy()->subYears($years - 1);

        // Determine calculation start year
        $calculationStartYear = $displayStartYear->copy();
        if ($hasInitialBalance && $initialDate && $initialDate->lt($displayStartYear)) {
            $calculationStartYear = $initialDate->copy();
        } elseif (!$hasInitialBalance && $firstGrantDate && $firstGrantDate->lt($displayStartYear)) {
            $calculationStartYear = $firstGrantDate->copy();
        }

        // Build list of all years from calculation start to current
        $yearsList = [];
        $tempStart = $calculationStartYear->copy();
        while ($tempStart->lte($currentFiscalYearStart)) {
            $yearsList[] = $tempStart->copy();
            $tempStart->addYear();
        }

        // Calculate year by year
        $previousRemaining = 0.0;
        $calculatedYears = [];

        foreach ($yearsList as $yearStart) {
            $yearEnd = $yearStart->copy()->addYear()->subDay();
            $isCurrentYear = $yearStart->eq($currentFiscalYearStart);

            // Usage for this fiscal year (up to today for current year)
            $usageEndDate = $isCurrentYear ? $today : $yearEnd;
            $usage = $this->calculateUsage($user, $yearStart, $usageEndDate);

            // Determine granted days and carryover
            $granted = 0.0;
            $carryover = 0.0;

            if ($hasInitialBalance) {
                if ($initialDate && $yearStart->format('Y-m-d') === $initialDate->format('Y-m-d')) {
                    // Initial year - use initial_leave_balance
                    $carryover = (float) $user->initial_leave_balance;
                    $granted = 0.0;
                } elseif ($initialDate && $yearStart->gt($initialDate)) {
                    // After initial year
                    $granted = (float) $this->calculateGrantDays($user, $yearStart, $useOldRule);
                    $carryover = min(self::MAX_CARRYOVER_DAYS, $previousRemaining);
                } else {
                    // Before initial year
                    $granted = 0.0;
                    $carryover = 0.0;
                }
            } elseif ($firstGrantDate) {
                // Calculate from hire date
                if ($yearStart->gte($firstGrantDate)) {
                    $granted = (float) $this->calculateGrantDays($user, $yearStart, $useOldRule);
                    $carryover = min(self::MAX_CARRYOVER_DAYS, $previousRemaining);
                } else {
                    // Before first grant
                    $granted = 0.0;
                    $carryover = 0.0;
                }
            } else {
                // Fallback to paid_leave_grants
                $grant = $user->paidLeaveGrants()
                    ->where('grant_date', $yearStart->format('Y-m-d'))
                    ->first();
                $granted = $grant ? (float) $grant->days_granted : 0.0;
                $carryover = min(self::MAX_CARRYOVER_DAYS, $previousRemaining);
            }

            $available = $carryover + $granted;
            $remaining = max(0, $available - $usage);
            $remaining = min(self::MAX_TOTAL_DAYS, $remaining);

            // Only include in output if within display range
            if ($yearStart->gte($displayStartYear)) {
                $calculatedYears[] = [
                    'fiscal_year' => $yearStart->format('Y') . '年度',
                    'period_start' => $yearStart,
                    'period_end' => $yearEnd,
                    'carryover' => $carryover,
                    'granted' => $granted,
                    'usage' => $usage,
                    'remaining' => $remaining,
                    'grant' => null,
                ];
            }

            $previousRemaining = $remaining;
        }

        // Reverse to show newest first
        return array_reverse($calculatedYears);
    }

    /**
     * Get detailed leave usage records for a user
     * Includes both paid_leave_usages and attendances tables
     */
    public function getLeaveUsageDetails(User $user, ?Carbon $fromDate = null, ?Carbon $toDate = null): Collection
    {
        $records = collect();
        $countedDates = [];

        // 1. Get from paid_leave_usages table
        $usageQuery = PaidLeaveUsage::where('user_id', $user->id);
        if ($fromDate) {
            $usageQuery->where('date', '>=', $fromDate);
        }
        if ($toDate) {
            $usageQuery->where('date', '<=', $toDate);
        }

        foreach ($usageQuery->get() as $usage) {
            $dateKey = $usage->date->format('Y-m-d') . '_' . $usage->leave_type;
            if (!isset($countedDates[$dateKey])) {
                $records->push([
                    'date' => $usage->date,
                    'type' => $usage->leave_type_label,
                    'days' => (float) $usage->days,
                    'note' => $usage->note,
                ]);
                $countedDates[$dateKey] = true;
            }
        }

        // 2. Get from attendances table
        $attendanceQuery = $user->attendances()
            ->whereIn('absence_reason', ['paid_leave', 'am_half_leave', 'pm_half_leave']);
        if ($fromDate) {
            $attendanceQuery->where('date', '>=', $fromDate);
        }
        if ($toDate) {
            $attendanceQuery->where('date', '<=', $toDate);
        }

        foreach ($attendanceQuery->get() as $attendance) {
            $dateKey = $attendance->date->format('Y-m-d') . '_' . $attendance->absence_reason;

            if (isset($countedDates[$dateKey])) {
                continue;
            }

            $typeLabel = match ($attendance->absence_reason) {
                'paid_leave' => '全休',
                'am_half_leave' => '午前半休',
                'pm_half_leave' => '午後半休',
                default => $attendance->absence_reason,
            };

            $days = match ($attendance->absence_reason) {
                'paid_leave' => 1.0,
                'am_half_leave', 'pm_half_leave' => 0.5,
                default => 0.0,
            };

            $records->push([
                'date' => $attendance->date,
                'type' => $typeLabel,
                'days' => $days,
                'note' => null,
            ]);
            $countedDates[$dateKey] = true;
        }

        // Sort by date descending
        return $records->sortByDesc('date')->values();
    }

    /**
     * Get leave usage details for the last N years
     */
    public function getLeaveUsageDetailsForYears(User $user, int $years = 5): Collection
    {
        $effectiveGrantDate = $user->effective_leave_grant_date;
        if (!$effectiveGrantDate) {
            // Fall back to 5 years from today
            $fromDate = Carbon::today()->subYears($years);
        } else {
            // Calculate from the earliest fiscal year we're showing
            $fromDate = $effectiveGrantDate->copy();
            $today = Carbon::today();
            while ($fromDate->copy()->addYear()->lte($today)) {
                $fromDate->addYear();
            }
            $fromDate->subYears($years - 1);
        }

        return $this->getLeaveUsageDetails($user, $fromDate);
    }
}
