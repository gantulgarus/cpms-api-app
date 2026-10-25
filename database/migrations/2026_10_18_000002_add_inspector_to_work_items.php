<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ажил бүрийн хяналт шалгалтыг хариуцах инженер.
 *
 * ЯАГААД ХЭРЭГТЭЙ ВЭ: төсөвт «Хяналт шалгалт хийх инженер» гэсэн багана
 * байдаг — Ланкастер А1 блокийн 64 мөрийн 28-д нь дүүргэгдсэн. Систем түүнийг
 * хадгалахгүй байсан тул гүйцэтгэл батлахыг хүлээж байгаа ажил ХЭН рүү
 * явахыг хэн ч мэдэхгүй: эрх бүхий 4 хүн бүгд бүх ажлыг хардаг.
 *
 * `inspection_requests.inspector_id` нь ӨӨР зүйл: тэр нь «хэн БАТАЛСАН»
 * гэдгийг хойно нь бичдэг. Энэ нь «хэн БАТЛАХ ёстой» гэсэн ТӨЛӨВЛӨГӨӨ.
 * Хоёрыг хольвол «батлаагүй байгаа ажил хэн рүү явах вэ» гэдэг хэзээ ч
 * тооцогдохгүй.
 *
 * Хоосон байж болно: төсвийн 36 мөрд инженер заагаагүй.
 *
 * `nullOnDelete` — хүн ажлаас гарахад ажлын мөр устах ёсгүй, зүгээр л
 * хариуцагчгүй болж «Хариуцагчгүй» самбарт харагдана.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->foreignId('inspector_id')->nullable()->after('contractor_id')
                ->constrained('users')->nullOnDelete();

            $table->index('inspector_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_items', function (Blueprint $table) {
            $table->dropIndex(['inspector_id']);
            $table->dropConstrainedForeignId('inspector_id');
        });
    }
};
