<?php

use App\History\ActivityScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organisation, product and version of each activity entry (App\History\ActivityScope), for
 * the customer histories of a product and of an organisation. No foreign keys: the history of a
 * deleted product or version stays until the retention prunes it (redact:prune-tracking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('organisation_id', 26)->nullable()->after('causer_id');
            $table->string('product_id', 26)->nullable()->after('organisation_id');
            $table->string('product_version_id', 26)->nullable()->after('product_id');
            $table->index(['product_id', 'id'], 'activity_log_product_index');
            $table->index(['organisation_id', 'id'], 'activity_log_organisation_index');
        });

        // Entries written before: scoped from their subjects where they still exist.
        if (class_exists(ActivityScope::class)) {
            ActivityScope::backfill();
        }
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_product_index');
            $table->dropIndex('activity_log_organisation_index');
            $table->dropColumn(['organisation_id', 'product_id', 'product_version_id']);
        });
    }
};
