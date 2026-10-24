<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guest sandbox ("Try without an account"): a guest is a throwaway account
     * (guest-<ulid>@guest.invalid) whose data is deleted 24 hours after its
     * last activity unless the person creates an account (App\Guest).
     *
     * request_logs.guest freezes, at write time, whether the visitor was a
     * sandbox guest (null: anonymous, or a row written before this column),
     * so the console can still split page views after a guest is purged.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->index();
            $table->timestamp('guest_expires_at')->nullable()->index();
        });

        Schema::table('request_logs', function (Blueprint $table) {
            $table->boolean('guest')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('request_logs', function (Blueprint $table) {
            $table->dropColumn('guest');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_guest']);
            $table->dropIndex(['guest_expires_at']);
            $table->dropColumn(['is_guest', 'guest_expires_at']);
        });
    }
};
