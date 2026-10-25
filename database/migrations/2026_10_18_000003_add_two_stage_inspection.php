<?php

use App\Models\Inspection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Хоёр шаттай баталгаажуулалт.
 *
 * ТГ илгээх → ТАЛБАЙН ИНЖЕНЕР шалгах → ХЯНАЛТЫН ИНЖЕНЕР батлах.
 *
 * ЯАГААД ДАХИН НЭМЭГДЭЖ БАЙНА ВЭ: шат нь өмнө нь байсан ч ХАСАГДСАН. Учир
 * нь хоёр шат НЭГ сангаас иддэг байв — `accepted_qty` нь шалгалтын нийлбэр
 * байсан тул талбайн инженер 100 м²-ийг «шалгасан» гэхэд хяналтын инженерт
 * батлах юу ч үлдэхгүй, актад орох хэмжээ 0 болдог байв. Шатыг хасах нь тэр
 * үеийн ЗӨВ шийдвэр байсан: худал тоо гаргахаас нэг шат дээр зөв ажиллах нь
 * дээр.
 *
 * Одоо үндсийг нь зассан: шат бүр ӨӨРИЙН сангаас иднэ.
 *
 *     checked_qty  = 1-р шатны шалгалтуудын нийлбэр   (мэдээлсэнээс иднэ)
 *     accepted_qty = 2-р шатны шалгалтуудын нийлбэр   (ШАЛГАСНААС иднэ)
 *
 * Тиймээс хяналтын инженер зөвхөн талбайн инженер шалгаж өнгөрүүлсэн
 * хэмжээг батална. Акт нь ЗӨВХӨН `accepted_qty`-г авна — өмнөх хувилбарт
 * хоёр шат хольж бодогдох эрсдэл үүний улмаас арилна.
 *
 * ШАТНЫ НЭР: хуучин `general_contractor` / `client` гэдэг нь ХЭН шалгахыг
 * бус, байгууллагын талыг нэрлэж байв. Бодит урсгалд хоёулаа Инэл ХХК-ийн
 * дотоод шат (талбайн инженер, хяналтын инженер) тул нэр нь уншигчийг
 * тогтмол андуурдаг. Шинэ нэр нь урсгалыг нэрлэнэ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_types', function (Blueprint $table) {
            /*
             * Ажлын төрөл хэдэн шаттай вэ.
             *
             * Захиалагчийн шийдвэрээр БҮГД 2 шаттай эхэлнэ. Далд хийгдэхгүй
             * ажилд (жишээ нь цэвэрлэгээ) хоёр шат нь дарамт болж магадгүй
             * тул ажлын төрлийн дэлгэцээс 1 болгож болдог байна.
             */
            $table->unsignedTinyInteger('inspection_stages')->default(2)->after('level');
        });

        Schema::table('work_items', function (Blueprint $table) {
            /*
             * 1-р шатаар шалгагдсан хэмжээ.
             *
             * `reported_qty` ба `accepted_qty` хоёрын ХООРОНД орших гуравдагч
             * сан. Үүнгүйгээр хоёр шат нэг тооноос иднэ — яг тэр шалтгаанаар
             * шат хасагдсан.
             */
            $table->decimal('checked_qty', 14, 3)->default(0)->after('reported_qty');
        });

        /*
         * Хуучин шалгалтуудын шатыг хөрвүүлнэ.
         *
         * `client` нь актад ордог ЭЦСИЙН батлалт байсан тул 2-р шат болно.
         * `general_contractor` нь түүнээс өмнөх шалгалт — 1-р шат.
         */
        DB::table('inspections')->where('stage', 'general_contractor')
            ->update(['stage' => Inspection::STAGE_SITE]);
        DB::table('inspections')->where('stage', 'client')
            ->update(['stage' => Inspection::STAGE_FINAL]);

        /*
         * Байгаа ажлын мөрүүдийн `checked_qty`-г НӨХНӨ.
         *
         * Хөрвүүлснээр хуучин шалгалтууд 2-р шат болсон. Хэрэв `checked_qty`
         * 0 хэвээр үлдвэл «баталсан нь шалгаснаас их» гэсэн боломжгүй төлөв
         * үүсч, дараагийн шалгалт бүр татгалзана. Тиймээс баталсан хэмжээг
         * шалгасан гэж үзнэ — бодитоор тэр ажил шалгагдаж баталгаажсан.
         */
        DB::statement('update work_items set checked_qty = accepted_qty where accepted_qty > 0');
    }

    public function down(): void
    {
        DB::table('inspections')->where('stage', Inspection::STAGE_SITE)
            ->update(['stage' => 'general_contractor']);
        DB::table('inspections')->where('stage', Inspection::STAGE_FINAL)
            ->update(['stage' => 'client']);

        Schema::table('work_items', fn (Blueprint $t) => $t->dropColumn('checked_qty'));
        Schema::table('work_types', fn (Blueprint $t) => $t->dropColumn('inspection_stages'));
    }
};
