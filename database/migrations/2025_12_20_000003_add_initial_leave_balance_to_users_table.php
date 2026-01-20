<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('initial_leave_balance', 4, 1)->nullable()->after('leave_grant_date')
                ->comment('初期残日数');
            $table->date('initial_leave_date')->nullable()->after('initial_leave_balance')
                ->comment('初期基準日');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['initial_leave_balance', 'initial_leave_date']);
        });
    }
};
