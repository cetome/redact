<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The manufacturer as the EU declaration of conformity names it (RED Annex VI, point 2): legal
 * name, postal address and country, and the authorised representative when there is one.
 * Edited by the organisation's administrators, shown in every declaration of its products.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->string('legal_name', 200)->nullable();
            $table->text('address')->nullable();
            $table->string('country', 80)->nullable();
            $table->string('authorised_rep_name', 200)->nullable();
            $table->text('authorised_rep_address')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'address', 'country', 'authorised_rep_name', 'authorised_rep_address']);
        });
    }
};
