<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('normalized_name')->nullable()->after('name')
                ->comment('照合用正規化氏名');
            $table->unsignedTinyInteger('leave_grant_month')->nullable()->after('leave_grant_date')
                ->comment('有給付与月（1〜12）');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['normalized_name', 'leave_grant_month']);
        });
    }
};
