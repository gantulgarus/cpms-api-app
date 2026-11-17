<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_catalog_entries', function (Blueprint $table) {
            $table->id();
            $table->string('source_key')->nullable()->unique();
            $table->unsignedInteger('source_row')->nullable();
            $table->unsignedInteger('sequence_number');
            $table->string('group_name');
            $table->string('subgroup_number', 50);
            $table->string('type_name');
            $table->string('name', 500);
            $table->string('unit', 20)->nullable();
            $table->text('location_description')->nullable();
            $table->text('floor_description')->nullable();
            $table->text('description')->nullable();
            $table->json('source_values')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_catalog_entries');
    }
};
