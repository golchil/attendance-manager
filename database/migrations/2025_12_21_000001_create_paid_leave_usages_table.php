<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->date('date')->comment('取得日');
            $table->string('leave_type')->comment('paid_leave / am_half_leave / pm_half_leave');
            $table->decimal('days', 3, 1)->comment('消化日数（1.0 / 0.5）');
            $table->text('note')->nullable()->comment('備考');
            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_usages');
    }
};
