<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Хэрэглэгчийн үүрэг ба хамрах хүрээ.
 *
 * Хамрах хүрээ (`scope_block_ids`) нь UI-д нуух зүйл БИШ — backend дээр
 * мөрдөгдөнө. Талбайн инженер өөрийн 1–2 блокоос гадуурх WorkItem-ийг API-аар
 * ч авч чадахгүй байх ёстой (RULE-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // director · general_engineer · project_manager · site_engineer
            // · inspector · warehouse · finance · contractor · admin
            $table->string('role', 30)->default('site_engineer')->after('email');
            $table->json('scope_block_ids')->nullable()->after('role');
            $table->uuid('contractor_id')->nullable()->after('scope_block_ids');
            $table->boolean('is_active')->default(true)->after('contractor_id');

            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'scope_block_ids', 'contractor_id', 'is_active']);
        });
    }
};
