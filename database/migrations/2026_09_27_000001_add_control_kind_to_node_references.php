<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2.7, W102 (PLAN-2.7 D64): a defence cites the framework control it
 * implements as a `control` reference, `FRAMEWORK:CONTROL`. The enum grows
 * by one value; the existing `(kind, identifier)` index is what answers
 * "which assessments cite ISO27001-2022:A.5.23?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_references', function (Blueprint $table) {
            $table->enum('kind', ['cve', 'cwe', 'capec', 'attack_technique', 'control', 'url', 'other'])->change();
        });
    }

    public function down(): void
    {
        // The narrower enum cannot hold them; a rollback loses the citations.
        DB::table('node_references')->where('kind', 'control')->delete();

        Schema::table('node_references', function (Blueprint $table) {
            $table->enum('kind', ['cve', 'cwe', 'capec', 'attack_technique', 'url', 'other'])->change();
        });
    }
};
