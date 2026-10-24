<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groups of inventory objects (EN 18031: same use case and protection level, handled as one):
     * an item with members is a group, each member {name, description?, code?, attributes?}.
     */
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->json('members')->nullable()->after('attributes');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('members');
        });
    }
};
