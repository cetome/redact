<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VEX (Vulnerability Exploitability eXchange): uploaded statements waiting for review, and
 * the manufacturer's analysis recorded per vulnerability and component (App\Sbom\Vex).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vex_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // An SBOM that embeds its VEX: reviewed once its components are imported (files kept with it).
            $table->foreignUlid('sbom_import_id')->nullable()->constrained()->nullOnDelete();
            $table->string('format', 40); // cyclonedx-json, cyclonedx-xml, openvex, csaf, mixed
            $table->json('files');
            $table->longText('statements'); // parsed statements (JSON), shown in the preview
            $table->unsignedInteger('statement_count')->default(0);
            $table->string('status', 12)->default('preview'); // preview | imported | discarded
            $table->json('summary')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vex_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('vulnerability_key', 80); // CVE id when there is one
            $table->json('aliases')->nullable();
            $table->foreignUlid('vulnerability_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            // The component; null: the product as a whole.
            $table->foreignUlid('inventory_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status', 24); // not_affected | affected | fixed | under_investigation
            $table->string('justification', 60)->nullable();
            $table->text('detail')->nullable(); // detail / impact statement
            $table->text('action')->nullable(); // action statement / response / remediation
            $table->string('format', 20);
            $table->string('source_file', 200);
            $table->foreignUlid('vex_import_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['product_version_id', 'vulnerability_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vex_statements');
        Schema::dropIfExists('vex_imports');
    }
};
