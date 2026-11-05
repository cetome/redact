<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An organisation's logo (Settings, and on public product pages when the manufacturer chooses) and a
 * person's picture (Account, shown instead of the initials). Both on the private disk
 * (App\Support\StoredPicture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('name');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('picture_path')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', fn (Blueprint $table) => $table->dropColumn('logo_path'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('picture_path'));
    }
};
