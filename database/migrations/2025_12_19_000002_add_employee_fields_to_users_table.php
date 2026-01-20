<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code')->unique()->nullable()->after('id');
            $table->string('card_number')->unique()->nullable()->after('employee_code');
            $table->foreignId('department_id')->nullable()->after('card_number')
                ->constrained('departments')->nullOnDelete();
            $table->string('position')->nullable()->after('department_id');
            $table->string('employment_type')->nullable()->after('position');
            $table->date('joined_at')->nullable()->after('employment_type');
            $table->boolean('is_active')->default(true)->after('joined_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropColumn([
                'employee_code',
                'card_number',
                'department_id',
                'position',
                'employment_type',
                'joined_at',
                'is_active',
            ]);
        });
    }
};
