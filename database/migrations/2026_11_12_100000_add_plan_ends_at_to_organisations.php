<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A plan set by staff until a date: a trial without a card (App\Billing\Plan). Null: no end (a contract).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->timestamp('plan_ends_at')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('plan_ends_at');
        });
    }
};
