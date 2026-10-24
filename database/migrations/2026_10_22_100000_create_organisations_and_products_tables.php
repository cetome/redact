<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenancy (organisations), products, product versions and per-product roles.
     */
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 160);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Customer opt-in: may product data and documents be sent to the AI provider?
            $table->boolean('ai_enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('organisation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member'); // admin | member
            $table->timestamps();

            $table->unique(['organisation_id', 'user_id']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('reference', 80)->nullable(); // manufacturer model / SKU
            $table->text('description')->nullable();
            $table->string('category', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // owner | contributor | reviewer
            // An external member does not belong to the product's organisation.
            $table->boolean('external')->default(false);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'user_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->string('email', 191);
            $table->string('role', 16);
            $table->boolean('external')->default(false);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'email']);
        });

        Schema::create('product_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->foreignUlid('previous_version_id')->nullable()->constrained('product_versions')->nullOnDelete();
            $table->string('status', 16)->default('draft'); // draft | in_review | approved | archived
            // The content pack version this assessment was made with.
            $table->string('content_pack', 64)->nullable();
            $table->text('intended_use')->nullable();
            $table->string('author_name', 120)->nullable();
            $table->date('completion_date')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'label']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_versions');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('product_members');
        Schema::dropIfExists('products');
        Schema::dropIfExists('organisation_members');
        Schema::dropIfExists('organisations');
    }
};
