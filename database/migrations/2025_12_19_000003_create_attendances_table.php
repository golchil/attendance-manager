<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('day_type')->nullable();
            $table->string('shift_code')->nullable();
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->time('go_out_at')->nullable();
            $table->time('return_at')->nullable();
            $table->unsignedInteger('break_minutes')->nullable();
            $table->unsignedInteger('work_minutes')->nullable();
            $table->string('status')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
