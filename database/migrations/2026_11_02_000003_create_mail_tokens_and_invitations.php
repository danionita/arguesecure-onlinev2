<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time links sent by email. Only a hash of the token is stored, so a
     * copy of the database cannot be used to follow a link. `reference` lets
     * another feature bind a token to something of its own (a pending
     * registration) without this table knowing about it.
     */
    public function up(): void
    {
        Schema::create('mail_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 20);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('reference', 100)->nullable();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Set when an invitation is mailed; the reminders count from it.
            $table->timestamp('invited_at')->nullable();
            $table->unsignedTinyInteger('invitation_reminders_sent')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['invited_at', 'invitation_reminders_sent']);
        });

        Schema::dropIfExists('mail_tokens');
    }
};
