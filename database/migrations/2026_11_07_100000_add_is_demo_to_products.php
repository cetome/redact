<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demo product an account loads from its dashboard (App\Guest\DemoProduct): read-only, never
 * counted by the plan (plan_slot "demo"), one per organisation. No extra index: the organisation
 * foreign key's index already narrows the lookup, and MariaDB would tie a composite one to that key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('plan_slot');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_demo');
        });
    }
};
