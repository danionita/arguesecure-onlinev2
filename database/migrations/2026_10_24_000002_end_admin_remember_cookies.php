<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An admin account gets no remember-me cookie from now on, so the ones
 * already issued to admins are ended here. The cookie is checked against the
 * user's remember token; clearing it makes every outstanding one fail and the
 * next sign-in issue none.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('is_admin', true)->update(['remember_token' => null]);
    }

    public function down(): void
    {
        // Nothing to give back: a cleared token cannot be restored, and a
        // rollback would not want it to be.
    }
};
