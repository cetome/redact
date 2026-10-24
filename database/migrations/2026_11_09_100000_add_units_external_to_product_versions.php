<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a version's assessment units are performed (its "Assessment units" step): in REDact (false,
 * the team records each result with its evidence) or outside REDact (true, an independent test
 * laboratory performs them and its report is attached in Documents). Outside REDact, missing units
 * block neither submission nor approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->boolean('units_external')->default(false)->after('conformity_route');
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn('units_external');
        });
    }
};
