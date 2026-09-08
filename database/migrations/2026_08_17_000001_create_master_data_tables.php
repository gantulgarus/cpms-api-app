<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Мастер өгөгдөл — компани, төсөл, гүйцэтгэгч, ажлын төрлийн сан.
 *
 * `work_types.level` нь энэ загварын гол шийдвэр: ажлын төрөл бүр ямар
 * байршлын түвшинд хянагдахыг заана. Дээвэр блокт нэг удаа, угсралт давхар
 * бүрт, паркет айл бүрт. Excel-д энэ мэдээлэл байхгүй тул ерөнхий инженертэй
 * тохирч оноодог — тиймээс кодод биш, хүснэгтэд байх ёстой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('registration_number')->nullable();
            $table->string('address')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('planned');
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::create('contractors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('trade_specialty')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            // Талбайн төлөөлөгч энэ кодоор нэвтэрнэ (захиалагчийн шаардлага).
            $table->string('access_code', 32)->nullable()->unique();
            $table->timestamp('access_code_expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('work_type_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');            // Угсралт, Өрлөг, Засал …
            $table->unsignedSmallInteger('sequence_number')->default(0);
            // Барилгын гүйцэтгэх дараалал — хуваарь болон эрэмбэлэлтэд.
            $table->unsignedSmallInteger('build_order')->default(5);
            $table->timestamps();
        });

        Schema::create('work_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_type_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->string('unit', 20);                       // м2 · м3 · ш
            $table->string('level', 20);                      // block · floor · unit
            $table->unsignedSmallInteger('sequence_number')->default(0);
            $table->timestamps();

            $table->index(['work_type_group_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_types');
        Schema::dropIfExists('work_type_groups');
        Schema::dropIfExists('contractors');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('companies');
    }
};
