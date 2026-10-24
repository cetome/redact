<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the reviewer saw: the reviewed fields of the evaluation (last decision node, path,
     * verdict, justification), so the review page can show what changed since. Null for reviews
     * given before this column existed.
     */
    public function up(): void
    {
        Schema::table('evaluation_reviews', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('evaluation_reviews', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
