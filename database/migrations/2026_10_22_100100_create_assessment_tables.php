<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assessment data of a product version: scoping, risk assessment,
     * inventory, decision-tree evaluations, E.Info documentation, reviews.
     *
     * The engine stores user data only. What the questions, lists, trees and
     * rules mean comes from the content pack (see app/Content).
     */
    public function up(): void
    {
        Schema::create('scoping_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('question', 64);
            $table->json('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_version_id', 'question']);
        });

        Schema::create('risk_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rank'); // 1 = most severe
            $table->string('name', 40);
            $table->char('color', 7);
            $table->timestamps();

            $table->unique(['product_version_id', 'rank']);
        });

        Schema::create('risk_matrix_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('impact');
            $table->unsignedTinyInteger('likelihood');
            $table->unsignedTinyInteger('level_rank');

            $table->unique(['product_version_id', 'impact', 'likelihood']);
        });

        Schema::create('risk_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('threat', 200);
            $table->text('description')->nullable();
            $table->json('stride')->nullable(); // ["S","T",...]
            $table->text('risk')->nullable();
            $table->unsignedTinyInteger('impact')->nullable();
            $table->unsignedTinyInteger('likelihood')->nullable();
            $table->json('articles')->nullable(); // ["3.3d","3.3e","3.3f"]
            $table->json('requirements')->nullable(); // ["AUM","ACM","GEC-2"]
            $table->text('remediation')->nullable();
            $table->text('implementation')->nullable();
            $table->unsignedTinyInteger('residual_rank')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('list', 40); // content-pack list key: assets, interfaces, entities, ...
            $table->string('code', 80);
            $table->string('name', 200)->nullable();
            $table->text('description')->nullable();
            $table->json('attributes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_version_id', 'list', 'code']);
        });

        Schema::create('inventory_links', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('relation', 40); // content-pack relation key: asset_interface, ...
            $table->foreignUlid('from_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUlid('to_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->json('attributes')->nullable();
            $table->timestamps();

            $table->unique(['relation', 'from_item_id', 'to_item_id']);
        });

        Schema::create('evaluations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('standard', 16); // EN18031-1 | EN18031-2 | EN18031-3
            $table->string('requirement', 24); // ACM-1, AUM-1-1, ...
            // Null for product-level requirements (e.g. NMM-1, TCM-1).
            $table->foreignUlid('inventory_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('decision_node', 16)->nullable(); // last DN reached, e.g. DN-4
            $table->json('path')->nullable(); // [{"node":"DN-1","answer":"No"}, ...]
            $table->string('verdict', 16)->nullable(); // PASS | FAIL | NOT_APPLICABLE
            $table->text('justification')->nullable(); // E.Just
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_version_id', 'standard', 'requirement', 'inventory_item_id'], 'evaluations_unique');
        });

        Schema::create('einfo_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('einfo', 120); // E.Info.ACM-1.SecurityAsset.PublicAccess
            $table->foreignUlid('inventory_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('text')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_version_id', 'einfo', 'inventory_item_id'], 'einfo_entries_unique');
        });

        Schema::create('evaluation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('evaluation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('verdict', 16); // agree | disagree | question
            $table->text('comment')->nullable();
            // Fingerprint of the evaluation when reviewed; a change makes the review stale.
            $table->char('fingerprint', 64);
            $table->timestamps();

            $table->unique(['evaluation_id', 'reviewer_id']);
        });

        Schema::create('version_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('verdict', 24); // approved | changes_requested
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['product_version_id', 'reviewer_id']);
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name', 255);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('path', 255); // on the private disk, never under public/
            $table->longText('extracted_text')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['documents', 'version_reviews', 'evaluation_reviews', 'einfo_entries', 'evaluations',
            'inventory_links', 'inventory_items', 'risk_entries', 'risk_matrix_cells', 'risk_levels',
            'scoping_answers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
