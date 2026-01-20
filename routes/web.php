<?php

use App\Http\Controllers\LeaveManagementLedgerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 印刷用ルート
Route::get('/leave-ledger/print/{user}', [LeaveManagementLedgerController::class, 'print'])
    ->name('leave-ledger.print')
    ->middleware('auth');
