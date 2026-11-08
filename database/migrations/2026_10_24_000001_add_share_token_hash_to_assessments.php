<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A share link is looked up by the SHA-256 of its token, so the
 * bytes the database compares are not the ones a visitor typed. The plaintext
 * stays, because the owner's page shows the link again and the policy
 * compares the session's copy against it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function ($table): void {
            $table->char('share_token_hash', 64)->nullable()->unique()->after('share_token');
        });

        // The hex digest MariaDB's SHA2() returns is the one PHP's
        // hash('sha256', ...) does, which Assessment keeps in step from now on.
        DB::statement('UPDATE assessments SET share_token_hash = SHA2(share_token, 256) WHERE share_token IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('assessments', function ($table): void {
            $table->dropUnique(['share_token_hash']);
            $table->dropColumn('share_token_hash');
        });
    }
};
