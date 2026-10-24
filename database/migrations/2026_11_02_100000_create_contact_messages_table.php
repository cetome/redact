<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages sent with the contact form (/contact): read-only in the staff console, deleted after
     * config('redact.retention.contact_messages_days') by redact:prune-tracking. The IP address is
     * kept as a keyed hash only (like request_logs). sent_at: the message reached the cetome inbox;
     * failure: why it did not (after the queue's last attempt).
     */
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('email', 150);
            $table->string('company', 150)->nullable();
            $table->string('topic', 20);
            $table->text('message');
            $table->string('locale', 8);
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('failure', 255)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
