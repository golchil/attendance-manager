<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PaidLeaveService;
use Illuminate\Http\Request;

class LeaveManagementLedgerController extends Controller
{
    public function print(Request $request, User $user)
    {
        $service = app(PaidLeaveService::class);

        $yearlySummary = $service->getYearlySummary($user, 5);
        $leaveDetails = $service->getLeaveUsageDetailsForYears($user, 5);
        $currentBalance = $service->calculateBalance($user);

        return view('print.leave-ledger', compact(
            'user',
            'yearlySummary',
            'leaveDetails',
            'currentBalance'
        ));
    }
}
