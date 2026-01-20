<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('card_name')->nullable()->after('name')
                ->comment('タイムカード上の名前');
            $table->string('normalized_card_name')->nullable()->after('card_name')
                ->comment('照合用の正規化されたタイムカード名');

            $table->index('normalized_card_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['normalized_card_name']);
            $table->dropColumn(['card_name', 'normalized_card_name']);
        });
    }
};
