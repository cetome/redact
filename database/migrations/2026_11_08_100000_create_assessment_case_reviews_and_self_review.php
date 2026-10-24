<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review = view and validate. The reviewers' opinion on each assessment unit result the manufacturer
 * provided (agree / disagree / question, like evaluation_reviews, stale when the result changes), and
 * the self-review flag: a review given by the product's owner or an organisation admin while the
 * product has no reviewer (App\Assessment\ReviewRights). Self-reviews stop counting once a reviewer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_case_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('assessment_case_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->string('verdict', 16); // agree | disagree | question
            $table->text('comment')->nullable();
            // Fingerprint and copy of the result when reviewed: a change makes the review stale.
            $table->char('fingerprint', 64);
            $table->json('snapshot')->nullable();
            $table->boolean('self_review')->default(false);
            $table->timestamps();

            $table->unique(['assessment_case_result_id', 'reviewer_id'], 'case_reviews_result_reviewer_unique');
        });

        foreach (['evaluation_reviews', 'version_reviews'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('self_review')->default(false)->after('comment');
            });
        }
    }

    public function down(): void
    {
        foreach (['evaluation_reviews', 'version_reviews'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('self_review');
            });
        }
        Schema::dropIfExists('assessment_case_reviews');
    }
};
