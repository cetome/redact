<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proof of the consent to start a paid service at once (EU consumer withdrawal right), one row per
 * paid action: Stripe Checkout, one more product, reactivation. Kept while the organisation exists;
 * the person is kept as null when their account is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // checkout | add_product | reactivate
            $table->string('action', 20);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('terms_version', 16);
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organisation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_consents');
    }
};
