<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keyboard shortcuts (APP-UI.md "Keyboard shortcuts"): on by default. When a person turns
     * them off, only the mod+ keys (save) and ? (the list of shortcuts) stay active.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('shortcuts_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('shortcuts_enabled');
        });
    }
};
