<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared walks (App\Assessment\SharedWalks): the same requirement for the same inventory item
     * (or the product) in several parts of EN 18031 whose trees are equivalent is answered once.
     *  - shared_group: evaluations kept in sync on every save (one per part);
     *  - shared_source: the part the shared answer was last saved in;
     *  - shared_untied: decided separately for this part, never filled in from another part.
     */
    public function up(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->ulid('shared_group')->nullable()->after('justification')->index();
            $table->string('shared_source', 16)->nullable()->after('shared_group');
            $table->boolean('shared_untied')->default(false)->after('shared_source');
        });
    }

    public function down(): void
    {
        Schema::table('evaluations', function (Blueprint $table) {
            $table->dropIndex(['shared_group']);
            $table->dropColumn(['shared_group', 'shared_source', 'shared_untied']);
        });
    }
};
