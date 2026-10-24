<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI provenance (same model as CRAted: AI output never lands in a human
     * field without a person accepting it) and usage tracking.
     */
    public function up(): void
    {
        Schema::create('ai_suggestions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('product_version_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40); // draft_from_documents | improve_text | check | suggest_path ...
            $table->string('target_type', 40); // product_version | inventory_item | evaluation | einfo_entry | risk_entry
            $table->string('target_key', 191); // record id or composite key
            $table->string('field', 64);
            $table->string('origin', 16); // ai_generated | ai_enhanced
            $table->json('payload'); // proposed value(s), findings, sources
            $table->string('status', 16)->default('pending'); // pending | accepted | rejected | superseded
            $table->string('provider', 32);
            $table->string('model', 80);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['product_version_id', 'target_type', 'target_key', 'field'], 'ai_suggestions_target');
        });

        // Which human fields currently hold text that came from the AI.
        Schema::create('field_origins', function (Blueprint $table) {
            $table->id();
            $table->string('target_type', 40);
            $table->string('target_key', 191);
            $table->string('field', 64);
            $table->string('origin', 16); // ai_generated | ai_enhanced
            $table->char('value_hash', 64); // hash of the accepted AI text
            $table->foreignUlid('ai_suggestion_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['target_type', 'target_key', 'field']);
        });

        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('product_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 40);
            $table->unsignedSmallInteger('units');
            $table->string('provider', 32);
            $table->string('model', 80);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('outcome', 16)->default('ok'); // ok | error | refused
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        // First-party usage analytics for the cetome admin dashboard.
        Schema::create('request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('method', 8);
            $table->string('route', 120)->nullable();
            $table->string('path', 255);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->string('product_id', 26)->nullable()->index();
            $table->string('version_id', 26)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('locale', 8)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_logs');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('field_origins');
        Schema::dropIfExists('ai_suggestions');
    }
};
