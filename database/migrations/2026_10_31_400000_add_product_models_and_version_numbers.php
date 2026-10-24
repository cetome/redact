<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product page (owner, 2026-10-23):
 *  - the models of a product are product-level: identifiers of kind "model" now belong to the
 *    product (product_id) instead of one version; the other kinds stay on the version;
 *  - the version of the product being assessed: hardware and software/firmware version,
 *    on the product version (never prefilled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_identifiers', function (Blueprint $table) {
            $table->foreignUlid('product_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
        Schema::table('product_identifiers', function (Blueprint $table) {
            $table->char('product_version_id', 26)->nullable()->change();
            $table->index(['product_id', 'position']);
        });

        Schema::table('product_versions', function (Blueprint $table) {
            $table->string('hardware_version', 80)->nullable()->after('intended_use');
            $table->string('software_version', 80)->nullable()->after('hardware_version');
        });

        // The models of each product's latest version become the product's models.
        $versions = DB::table('product_versions')->orderByDesc('created_at')->get(['id', 'product_id']);
        $latest = [];
        foreach ($versions as $version) {
            $latest[$version->product_id] ??= $version->id;
        }
        foreach ($latest as $productId => $versionId) {
            $models = DB::table('product_identifiers')->where('product_version_id', $versionId)
                ->where('kind', 'model')->orderBy('position')->get(['value', 'notes']);
            $position = 0;
            $seen = [];
            foreach ($models as $model) {
                if (isset($seen[$model->value])) {
                    continue;
                }
                $seen[$model->value] = true;
                DB::table('product_identifiers')->insert([
                    'product_id' => $productId, 'product_version_id' => null, 'kind' => 'model',
                    'value' => $model->value, 'notes' => $model->notes, 'position' => $position++,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        DB::table('product_identifiers')->whereNotNull('product_version_id')->where('kind', 'model')->delete();
    }

    public function down(): void
    {
        DB::table('product_identifiers')->whereNull('product_version_id')->delete();
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn(['hardware_version', 'software_version']);
        });
        Schema::table('product_identifiers', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'position']);
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
