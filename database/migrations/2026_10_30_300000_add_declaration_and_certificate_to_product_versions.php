<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The last step, "Declaration & technical file" (App\Declaration):
 *
 *  - product_versions.declaration: what the EU declaration of conformity (RED Annex VI) needs and
 *    the app does not know (other legislation, standards for 3.1(a), 3.1(b), 3.2, signatory…);
 *  - the Notified Body's certificate, recorded once it is issued, after the review:
 *    certificate_reference (kept, now the certificate number), its dates, the issuing body and
 *    the uploaded PDF (a document of the version).
 *
 * Existing certificate references were typed on the Conformity step: the issuing body is taken
 * from the Notified Body chosen there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->json('declaration')->nullable();
            $table->date('certificate_issued_on')->nullable();
            $table->date('certificate_expires_on')->nullable();
            $table->string('certificate_body', 160)->nullable();
            $table->string('certificate_body_number', 8)->nullable();
            $table->string('certificate_document_id', 26)->nullable();
        });

        DB::table('product_versions')->whereNotNull('certificate_reference')->where('certificate_reference', '!=', '')
            ->update([
                'certificate_body' => DB::raw('notified_body'),
                'certificate_body_number' => DB::raw('notified_body_number'),
            ]);
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn(['declaration', 'certificate_issued_on', 'certificate_expires_on', 'certificate_body', 'certificate_body_number', 'certificate_document_id']);
        });
    }
};
