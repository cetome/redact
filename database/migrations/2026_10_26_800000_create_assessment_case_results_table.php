<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The verdict of one assessment case of the standard (conceptual, functional completeness,
     * functional sufficiency) for one requirement of one version, with its evidence. Recorded by
     * people in the Review step; never copied to a new version (like reviews).
     */
    public function up(): void
    {
        Schema::create('assessment_case_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('standard', 40);
            $table->string('requirement', 40);
            $table->string('case_key', 40); // conceptual | functional_completeness | functional_sufficiency
            $table->string('verdict', 16); // PASS | FAIL | NOT_APPLICABLE
            $table->text('evidence');
            $table->foreignUlid('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            // Computed when saved: a reviewer of the product who did not last update any evaluation
            // of the requirement. Otherwise "self-assessed".
            $table->boolean('independent')->default(false);
            $table->timestamps();

            $table->unique(['product_version_id', 'standard', 'requirement', 'case_key'], 'assessment_case_results_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_case_results');
    }
};
