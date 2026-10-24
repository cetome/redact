<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Organisation invitations (organisation Team page): one row for the organisation
     * (organisation_id, organisation_role, the e-mailed token, no product), and one child row per
     * product offered in the same step (parent_id, product_id, role, no token of its own). The
     * children count as pending seats on their product (App\Billing\Plan) and are accepted with
     * the parent.
     */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignUlid('organisation_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('organisation_role', 16)->nullable()->after('organisation_id'); // admin | member
            $table->foreignUlid('parent_id')->nullable()->after('organisation_role')->constrained('invitations')->cascadeOnDelete();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->ulid('product_id')->nullable()->change();
            $table->string('role', 16)->nullable()->change();
            $table->char('token_hash', 64)->nullable()->change();
            $table->index(['organisation_id', 'email']);
        });
    }

    public function down(): void
    {
        // Organisation invitations have no product: they cannot survive the old columns.
        DB::table('invitations')->whereNull('product_id')->delete();
        DB::table('invitations')->whereNotNull('parent_id')->delete();

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['organisation_id', 'email']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('organisation_id');
            $table->dropColumn('organisation_role');
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->ulid('product_id')->nullable(false)->change();
            $table->string('role', 16)->nullable(false)->change();
            $table->char('token_hash', 64)->nullable(false)->change();
        });
    }
};
