<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\PaidLeaveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaidLeaveServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PaidLeaveService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaidLeaveService();
    }

    /**
     * Case A: initial_leave_balance=40.0 (old rule veteran employee)
     * Expected: granted=20, carryover=20
     */
    public function test_initial_year_balance_40_splits_into_granted_20_carryover_20(): void
    {
        // Create a veteran employee with old rule (joined 1995, grant date 1996/01/01)
        $user = User::factory()->create([
            'joined_at' => Carbon::create(1995, 1, 21),
            'leave_grant_date' => Carbon::create(1996, 1, 1),
            'leave_grant_month' => 1,
            'initial_leave_date' => Carbon::create(2022, 1, 1),
            'initial_leave_balance' => 40.0,
            'is_active' => true,
        ]);

        // Get yearly summary
        $summary = $this->service->getYearlySummary($user, 5);

        // Find the initial year (2022年度)
        $initialYearSummary = collect($summary)->first(function ($year) {
            return $year['period_start']->format('Y-m-d') === '2022-01-01';
        });

        $this->assertNotNull($initialYearSummary, 'Initial year 2022 should be in summary');
        $this->assertEquals(20.0, $initialYearSummary['granted'], 'Granted should be 20');
        $this->assertEquals(20.0, $initialYearSummary['carryover'], 'Carryover should be 20');
        $this->assertEquals(40.0, $initialYearSummary['carryover'] + $initialYearSummary['granted'], 'Total should be 40');
    }

    /**
     * Case B: initial_leave_balance=15.0
     * Expected: granted=0, carryover=15.0 (no split because <= 20)
     */
    public function test_initial_year_balance_15_no_split(): void
    {
        $user = User::factory()->create([
            'joined_at' => Carbon::create(1995, 1, 21),
            'leave_grant_date' => Carbon::create(1996, 1, 1),
            'leave_grant_month' => 1,
            'initial_leave_date' => Carbon::create(2022, 1, 1),
            'initial_leave_balance' => 15.0,
            'is_active' => true,
        ]);

        $summary = $this->service->getYearlySummary($user, 5);

        $initialYearSummary = collect($summary)->first(function ($year) {
            return $year['period_start']->format('Y-m-d') === '2022-01-01';
        });

        $this->assertNotNull($initialYearSummary, 'Initial year 2022 should be in summary');
        $this->assertEquals(0.0, $initialYearSummary['granted'], 'Granted should be 0');
        $this->assertEquals(15.0, $initialYearSummary['carryover'], 'Carryover should be 15');
    }

    /**
     * Case C: initial_leave_balance=25.0
     * Expected: granted=20, carryover=5
     */
    public function test_initial_year_balance_25_splits_into_granted_20_carryover_5(): void
    {
        $user = User::factory()->create([
            'joined_at' => Carbon::create(1995, 1, 21),
            'leave_grant_date' => Carbon::create(1996, 1, 1),
            'leave_grant_month' => 1,
            'initial_leave_date' => Carbon::create(2022, 1, 1),
            'initial_leave_balance' => 25.0,
            'is_active' => true,
        ]);

        $summary = $this->service->getYearlySummary($user, 5);

        $initialYearSummary = collect($summary)->first(function ($year) {
            return $year['period_start']->format('Y-m-d') === '2022-01-01';
        });

        $this->assertNotNull($initialYearSummary, 'Initial year 2022 should be in summary');
        $this->assertEquals(20.0, $initialYearSummary['granted'], 'Granted should be 20');
        $this->assertEquals(5.0, $initialYearSummary['carryover'], 'Carryover should be 5');
        $this->assertEquals(25.0, $initialYearSummary['carryover'] + $initialYearSummary['granted'], 'Total should be 25');
    }

    /**
     * Case D: initial_leave_balance=20.0 (boundary case)
     * Expected: granted=0, carryover=20.0 (no split because == 20)
     */
    public function test_initial_year_balance_20_no_split(): void
    {
        $user = User::factory()->create([
            'joined_at' => Carbon::create(1995, 1, 21),
            'leave_grant_date' => Carbon::create(1996, 1, 1),
            'leave_grant_month' => 1,
            'initial_leave_date' => Carbon::create(2022, 1, 1),
            'initial_leave_balance' => 20.0,
            'is_active' => true,
        ]);

        $summary = $this->service->getYearlySummary($user, 5);

        $initialYearSummary = collect($summary)->first(function ($year) {
            return $year['period_start']->format('Y-m-d') === '2022-01-01';
        });

        $this->assertNotNull($initialYearSummary, 'Initial year 2022 should be in summary');
        $this->assertEquals(0.0, $initialYearSummary['granted'], 'Granted should be 0');
        $this->assertEquals(20.0, $initialYearSummary['carryover'], 'Carryover should be 20');
    }

    /**
     * Test that remaining balance is preserved even with usage
     * Verifies that the split does not affect the final remaining calculation
     */
    public function test_remaining_balance_preserved_with_usage(): void
    {
        $user = User::factory()->create([
            'joined_at' => Carbon::create(1995, 1, 21),
            'leave_grant_date' => Carbon::create(1996, 1, 1),
            'leave_grant_month' => 1,
            'initial_leave_date' => Carbon::create(2022, 1, 1),
            'initial_leave_balance' => 40.0,
            'is_active' => true,
        ]);

        // Create leave usage records
        $user->paidLeaveUsages()->create([
            'date' => Carbon::create(2022, 1, 12),
            'leave_type' => 'am_half_leave',
            'days' => 0.5,
        ]);
        $user->paidLeaveUsages()->create([
            'date' => Carbon::create(2022, 1, 15),
            'leave_type' => 'paid_leave',
            'days' => 1.0,
        ]);

        $summary = $this->service->getYearlySummary($user, 5);

        $initialYearSummary = collect($summary)->first(function ($year) {
            return $year['period_start']->format('Y-m-d') === '2022-01-01';
        });

        $this->assertNotNull($initialYearSummary, 'Initial year 2022 should be in summary');

        // Verify split
        $this->assertEquals(20.0, $initialYearSummary['granted'], 'Granted should be 20');
        $this->assertEquals(20.0, $initialYearSummary['carryover'], 'Carryover should be 20');

        // Verify usage
        $this->assertEquals(1.5, $initialYearSummary['usage'], 'Usage should be 1.5 days');

        // Verify remaining: 40.0 - 1.5 = 38.5
        $this->assertEquals(38.5, $initialYearSummary['remaining'], 'Remaining should be 38.5');
    }

    /**
     * Test calculateBalanceFromInitial also uses the split logic
     */
    public function test_calculate_balance_uses_split_logic(): void
    {
        $user = User::factory()->create([
            'joined_at' => Carbon::create(1995, 1, 21),
            'leave_grant_date' => Carbon::create(1996, 1, 1),
            'leave_grant_month' => 1,
            'initial_leave_date' => Carbon::create(2022, 1, 1),
            'initial_leave_balance' => 40.0,
            'is_active' => true,
        ]);

        // Create leave usage records
        $user->paidLeaveUsages()->create([
            'date' => Carbon::create(2022, 1, 12),
            'leave_type' => 'am_half_leave',
            'days' => 0.5,
        ]);
        $user->paidLeaveUsages()->create([
            'date' => Carbon::create(2022, 1, 15),
            'leave_type' => 'paid_leave',
            'days' => 1.0,
        ]);

        $balance = $this->service->calculateBalance($user);

        // total_granted should include initial balance (carryover + granted for initial year)
        $this->assertArrayHasKey('total_granted', $balance);
        $this->assertArrayHasKey('total_used', $balance);
        $this->assertArrayHasKey('total_remaining', $balance);
    }

    /**
     * Test with half-day increments
     */
    public function test_half_day_increments_preserved(): void
    {
        $user = User::factory()->create([
            'joined_at' => Carbon::create(1995, 1, 21),
            'leave_grant_date' => Carbon::create(1996, 1, 1),
            'leave_grant_month' => 1,
            'initial_leave_date' => Carbon::create(2022, 1, 1),
            'initial_leave_balance' => 25.5, // Half-day increment
            'is_active' => true,
        ]);

        $summary = $this->service->getYearlySummary($user, 5);

        $initialYearSummary = collect($summary)->first(function ($year) {
            return $year['period_start']->format('Y-m-d') === '2022-01-01';
        });

        $this->assertNotNull($initialYearSummary, 'Initial year 2022 should be in summary');
        $this->assertEquals(20.0, $initialYearSummary['granted'], 'Granted should be 20');
        $this->assertEquals(5.5, $initialYearSummary['carryover'], 'Carryover should be 5.5');
        $this->assertEquals(25.5, $initialYearSummary['carryover'] + $initialYearSummary['granted'], 'Total should be 25.5');
    }
}
