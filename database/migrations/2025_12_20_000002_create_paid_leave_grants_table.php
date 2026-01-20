<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('grant_date')->comment('付与日');
            $table->decimal('days_granted', 4, 1)->comment('付与日数');
            $table->date('fiscal_year_start')->comment('年度開始日');
            $table->date('expires_at')->comment('有効期限（2年後）');
            $table->text('note')->nullable()->comment('備考');
            $table->timestamps();

            $table->index(['user_id', 'grant_date']);
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grants');
    }
};
