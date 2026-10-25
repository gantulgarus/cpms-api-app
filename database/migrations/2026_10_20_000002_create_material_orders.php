<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Материалын захиалга — ОЛОН МӨРТЭЙ.
 *
 * ЭХ СУРВАЛЖ: захиалагчийн дөрвөн бланк —
 *   «УГСРАЛТЫН ҮНДСЭН БАРАА МАТЕРИАЛЫН ЗАХИАЛГЫН ХУУДАС» (27 мөр)
 *   «УГСРАЛТЫН ТУСЛАХ …»  (16 мөр)
 *   «УГСРАЛТЫН МОДОН …»   (3 мөр)
 *   «АРМАТУР ЗАХИАЛГА, НИЙЛҮҮЛЭЛТИЙН ХУУДАС» (12 мөр)
 *
 * ДӨРӨВ БИШ НЭГ БОЛОМЖ. Эхний гурав нь багана болгоноороо ИЖИЛ — зөвхөн
 * материалын жагсаалт нь өөр. Дөрөв тусдаа хүснэгт, дөрвөн дэлгэц
 * барих нь ижил алдааг дөрвөн газар засах гэсэн үг.
 *
 * БЕТОНООС ЯАГААД ТУСДАА ВЭ: бетон нь НЭГ цутгалтын захиалга — огноо,
 * цаг, марк, конусан суулт, помп гэсэн зөвхөн түүнд хамаарах талбартай.
 * Түүнийг олон мөртэй болговол талбаруудын тал нь мөр бүрт утгагүй
 * давтагдана. Хоёр өөр баримт — хоёр өөр хүснэгт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /** Автомат дугаар: «МЗ-2026-001». */
            $table->string('number', 32)->unique();

            $table->foreignUuid('block_id')->constrained()->cascadeOnDelete();

            /*
             * Бланкны ТӨРӨЛ: main | aux | timber | rebar.
             *
             * Зөвхөн хэвлэх загвар ба шүүлтэд нөлөөлнө — өгөгдлийн бүтэц
             * дөрвүүлэнд нь ижил. Арматурын хуудас дээр нэгж жин, нийт
             * жин нэмж гарна.
             */
            $table->string('kind', 16);

            /*
             * Хүргэх байршил — СОНГОМОЛ.
             *
             * Бетон нь тодорхой давхарт цутгагддаг тул байршил заавал.
             * Пум, хадаас нь агуулахад ирээд дараа нь тарааг даг — «аль
             * давхарт вэ» гэдэг нь ихэвчлэн мэдэгддэггүй. Албадвал хүн
             * санамсаргүй давхар сонгож, үлдэгдлийн тооцоо худал болно.
             */
            $table->foreignUuid('location_id')->nullable()->constrained()->nullOnDelete();

            /** «Нийлүүлэх хугацаа хоног» мөрөөс гарах шаардах огноо. */
            $table->date('needed_by')->nullable();

            /**
             * draft | submitted | confirmed | delivered | cancelled —
             * бетон захиалгатай ИЖИЛ мөчлөг.
             */
            $table->string('status', 16)->default('draft');

            $table->text('notes')->nullable();

            /** Гурван гарын үсэг — бланк дээрх дараалал. */
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['block_id', 'status']);
            $table->index(['block_id', 'kind']);
        });

        Schema::create('material_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('material_order_id')->constrained()->cascadeOnDelete();

            /*
             * Лавлахын материал — ХООСОН БАЙЖ БОЛНО.
             *
             * Бланк дээр урьдчилан хэвлэгдсэн 27 мөрийн зарим нь төсөвт
             * байхгүй (жишээ нь «Шатны тор»). Тэднийг захиалж болохгүй
             * гэж хориглох нь талбайн ажлыг зогсооно — гараар бичсэн
             * нэрээр захиална, зөвхөн төсвийн үлдэгдэл харагдахгүй.
             */
            $table->foreignUuid('material_id')->nullable()->constrained()->nullOnDelete();

            /** Хэвлэгдэх нэр — лавлахаас ХУУЛБАРЛАНА эсвэл гараар. */
            $table->string('name');
            $table->string('unit', 16);

            /*
             * «Агуулах үлдэгдэл» — ГАРААР бичигдэнэ.
             *
             * Систем агуулахын бүртгэл ХӨТЛӨДӨГГҮЙ. Пум, хадаас нь
             * хадгалагдаж, дахин хэрэглэгддэг тул бодит үлдэгдлийг
             * мэдэхийн тулд орлого, зарлага, буцаалтыг бүртгэх хэрэгтэй —
             * тэр нь тусдаа, илүү том ажил. Одоохондоо агуулахын хүн
             * тоогоо бичнэ; баталгаажуулагч түүнийг хараад шийднэ.
             */
            $table->decimal('stock_qty', 14, 3)->nullable();

            $table->decimal('quantity', 14, 3);

            /*
             * «Зөвшөөрсөн тоо хэмжээ» — захиалсантай ЗӨРЖ болно.
             *
             * Бланк дээр тусдаа багана байгаа нь санамсаргүй биш:
             * баталгаажуулагч 100 ширхэг хүссэнийг 60 болгон бууруулдаг.
             * Нэг багана болговол тэр шийдвэр ул мөргүй алга болно.
             */
            $table->decimal('approved_qty', 14, 3)->nullable();
            $table->decimal('delivered_qty', 14, 3)->nullable();
            $table->date('delivered_at')->nullable();

            /*
             * Арматурын хуудсанд гардаг нэгж жин (кг).
             *
             * «Ф12 /12м/ — 10.656 кг» гэх мэт. Нийт жинг эндээс бодно:
             * үйлдвэр ширхгээр биш ТОННООР нийлүүлдэг тул хоёулаа
             * хэвлэгдэх ёстой.
             */
            $table->decimal('unit_weight_kg', 12, 3)->nullable();

            $table->string('note')->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            $table->index('material_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_order_lines');
        Schema::dropIfExists('material_orders');
    }
};
