<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory lists a person marked "This product has none" (e.g. no logged events), as a JSON
     * list of pack list keys. Such a list counts as done; adding an object to it clears the mark.
     */
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->json('inventory_none')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn('inventory_none');
        });
    }
};
