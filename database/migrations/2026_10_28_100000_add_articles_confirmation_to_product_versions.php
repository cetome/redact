<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Articles & standards" step: who confirmed the articles in scope against the product risks,
     * when, and a fingerprint of what they saw (App\Assessment\ArticlesCheck). A later scoping or
     * risk change that alters the comparison changes the fingerprint: the step is then to re-confirm.
     * A new version starts unconfirmed (VersionFactory does not copy these).
     */
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->timestamp('articles_confirmed_at')->nullable();
            $table->foreignId('articles_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('articles_confirmed_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('articles_confirmed_by');
            $table->dropColumn(['articles_confirmed_at', 'articles_confirmed_hash']);
        });
    }
};
