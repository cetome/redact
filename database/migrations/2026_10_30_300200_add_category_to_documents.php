<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an uploaded document goes in the technical documentation (RED Annex V headings, see
 * App\Declaration\TechnicalFile::CATEGORIES). Null: not filed yet ("Other documents").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('category', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
