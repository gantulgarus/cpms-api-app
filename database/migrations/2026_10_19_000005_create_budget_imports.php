<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Төсвийн импортын бүртгэл.
 *
 * ЯАГААД ХҮСНЭГТ ХЭРЭГТЭЙ ВЭ: импорт нь хоёр алхамтай — эхлээд урьдчилан
 * харах, дараа нь батлах. Хоёрын хооронд файл хаа нэгтээ байх ёстой.
 * Хөтөчөөс хоёр дахин илгээх нь АЮУЛТАЙ: хоёр дахь удаад өөр файл сонгож
 * болох бөгөөд хүн «харсан зүйлээ» баталсан гэж итгээд өөр зүйл орно.
 *
 * ХОЁР ДАХЬ ЗОРИЛГО — ТҮҮХ. «Энэ блокийн тоо хэмжээ хэзээ, хэнээр, ямар
 * файлаас орсон бэ» гэдэг нь маргаан гарахад хамгийн эхэнд асуух асуулт.
 * Файл нь хадгалагдсан хэвээр үлдэнэ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();

            /*
             * Таслалаар тусгаарласан төрлүүд: `quantities`, `materials`,
             * эсвэл `quantities,materials`.
             *
             * НЭГ ФАЙЛД ХОЁУЛАА байж болно — захиалагчийн 29 хуудастай
             * файлын хоёр нь яг тэр. Нэг л байршуулалт, нэг транзакц.
             */
            $table->string('type', 64);

            /** Хэрэглэгчийн файлын жинхэнэ нэр — тайланд харуулна. */
            $table->string('original_name');

            /** `storage/app/private/imports/…` доторх зам. */
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->default(0);

            /**
             * pending   — байршуулсан, урьдчилан харсан, батлаагүй
             * applied   — бичигдсэн
             * discarded — хэрэглэгч цуцалсан
             */
            $table->string('status', 16)->default('pending');

            /*
             * Урьдчилан харалтын тоонууд.
             *
             * Тайланг бүхэлд нь хадгалахгүй — алдааны жагсаалт 300 мөр
             * байж болно. Зөвхөн тоонууд: хэдэн мөр орсон, хэд
             * татгалзсан. Дэлгэрэнгүйг файлаас дахин задалж гаргана.
             */
            $table->json('summary')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();

            $table->timestamps();

            $table->index(['block_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_imports');
    }
};
