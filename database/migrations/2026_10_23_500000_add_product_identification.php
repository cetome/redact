<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product picture, the identifiers a version covers (models, trade names,
     * HVIN/FVIN...) and the conformity assessment route (self-assessment or
     * EU-type examination by a Notified Body).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image_path', 255)->nullable()->after('category'); // private disk
        });

        Schema::table('product_versions', function (Blueprint $table) {
            $table->string('conformity_route', 16)->nullable()->after('intended_use'); // module_a | module_b_c | module_h
            $table->string('notified_body', 160)->nullable()->after('conformity_route');
            $table->string('notified_body_number', 8)->nullable()->after('notified_body');
            $table->string('certificate_reference', 120)->nullable()->after('notified_body_number');
        });

        Schema::create('product_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('value', 160);
            $table->string('notes', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_version_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_identifiers');
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn(['conformity_route', 'notified_body', 'notified_body_number', 'certificate_reference']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
