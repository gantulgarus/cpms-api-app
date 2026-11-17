<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_catalog_entries', fn (Blueprint $table) => $table->string('location_level_override', 20)->nullable());
        // Preserve any location choices already made before automatic resolution.
        foreach (DB::table('work_catalog_entries')->whereNotNull('work_type_id')->get() as $entry) {
            $level = DB::table('work_types')->where('id', $entry->work_type_id)->value('level');
            DB::table('work_catalog_entries')->where('id', $entry->id)->update(['location_level_override' => $level]);
        }
    }

    public function down(): void
    {
        Schema::table('work_catalog_entries', fn (Blueprint $table) => $table->dropColumn('location_level_override'));
    }
};
