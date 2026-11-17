<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_catalog_entries', function (Blueprint $table) {
            $table->foreignUuid('work_type_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_catalog_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_type_id');
        });
    }
};
