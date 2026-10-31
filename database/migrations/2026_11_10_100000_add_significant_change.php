<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Significant change between versions (App\Assessment\SignificantChange).
 *
 * - source_id: the inventory item or risk a copied one comes from (VersionFactory), so a version is
 *   compared with the one it was made from even after codes or threats are renamed. No foreign
 *   key: the earlier version may be deleted.
 * - change_*: the decision on the version, "significant" or "not_significant", its justification,
 *   who took it, when, and the fingerprint of the signs it was taken on (another fingerprint: the
 *   decision is out of date).
 * - recheck: an evaluation or E.Info entry to confirm again after a significant change; cleared by
 *   "Still valid" or by any change of its content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->ulid('source_id')->nullable()->index();
        });
        Schema::table('risk_entries', function (Blueprint $table) {
            $table->ulid('source_id')->nullable()->index();
        });
        Schema::table('evaluations', function (Blueprint $table) {
            $table->boolean('recheck')->default(false);
        });
        Schema::table('einfo_entries', function (Blueprint $table) {
            $table->boolean('recheck')->default(false);
        });
        Schema::table('product_versions', function (Blueprint $table) {
            $table->string('change_decision', 20)->nullable();
            $table->text('change_justification')->nullable();
            $table->foreignId('change_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('change_decided_at')->nullable();
            $table->string('change_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('change_decided_by');
            $table->dropColumn(['change_decision', 'change_justification', 'change_decided_at', 'change_fingerprint']);
        });
        Schema::table('einfo_entries', fn (Blueprint $table) => $table->dropColumn('recheck'));
        Schema::table('evaluations', fn (Blueprint $table) => $table->dropColumn('recheck'));
        foreach (['risk_entries', 'inventory_items'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['source_id']);
                $table->dropColumn('source_id');
            });
        }
    }
};
