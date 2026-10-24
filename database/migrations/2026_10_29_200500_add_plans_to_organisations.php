<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans (App\Billing\Plan): every organisation's first product is free, each additional one
 * is paid (Stripe subscription "products", quantity = paid products) or granted by cetome.
 *
 *  - organisations.plan: free (self-service) | enterprise (custom contract, set by staff);
 *  - organisations.limits: per-organisation overrides set by staff (null = the config defaults);
 *  - products.plan_slot: which allowance a product was created on (free | granted | paid).
 *    Null = created before plans existed: grandfathered, never read-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->string('plan', 32)->default('free');
            $table->json('limits')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('plan_slot', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('plan_slot');
        });

        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn(['plan', 'limits']);
        });
    }
};
