<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public product page (App\PublicPage).
 *
 * - products.public_code: the product's fixed address /verify/{code} and its QR code, given on the
 *   first publication and kept for every later version.
 * - product_versions.public_page: what the manufacturer chose to show (PublicSettings), saved
 *   while choosing; published_at/published_by: the version is on the public page since then.
 * - organisations.security_contact: the default security contact (e-mail or web address) the
 *   public page settings start from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('public_code', 12)->nullable()->unique();
        });
        Schema::table('product_versions', function (Blueprint $table) {
            $table->json('public_page')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('organisations', function (Blueprint $table) {
            $table->string('security_contact', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table) {
            $table->dropColumn('security_contact');
        });
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn(['public_page', 'published_at']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['public_code']);
            $table->dropColumn('public_code');
        });
    }
};
