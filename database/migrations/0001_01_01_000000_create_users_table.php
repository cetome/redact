<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Accounts are passwordless: e-mail link/code as first factor, passkeys as
     * the primary sign-in method (see app/Auth).
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 191)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('organisation_name', 160)->nullable();
            $table->string('locale', 8)->default('en');
            $table->string('timezone', 64)->default('Europe/Paris');
            $table->boolean('active')->default(true);
            // Incremented to revoke every session of the account at once.
            $table->unsignedInteger('session_epoch')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_method', 32)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 16)->nullable();
            $table->boolean('wants_product_news')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 16); // login | signup | email_change
            $table->char('token_hash', 64)->unique();
            $table->char('code_hash', 64);
            $table->char('bind_hash', 64);
            $table->string('new_email', 191)->nullable();
            $table->string('intended', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'purpose']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('login_tokens');
        Schema::dropIfExists('users');
    }
};
