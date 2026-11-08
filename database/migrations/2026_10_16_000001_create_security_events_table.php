<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The security event log (security.md §11, schema.md §3.10a) was only a
 * line on stderr, so nothing in the application could read it back. The `security` channel now
 * also stores each record here, after redaction, so an admin can download it.
 * Append-only to the application; `arguesecure:purge-security-events` is the
 * only thing that deletes.
 *
 * No foreign key to `users`: a log row must outlive the account it names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->dateTime('created_at')->index();
            $table->string('level', 16);
            $table->string('event', 64);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_email', 254)->nullable();
            $table->json('target')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('details')->nullable();

            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
