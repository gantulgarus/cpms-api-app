<?php

use App\Models\WorkItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Хуучин татгалзсан ажлуудын дүнг дахин тооцоолно.
 *
 * ЯАГААД ХЭРЭГТЭЙ: `reported_qty` нь одоо ТАТГАЛЗСАН хэмжээг хассан цэвэр
 * дүн болсон. Гэвч энэ тооцоо зөвхөн ШИНЭ гүйцэтгэл эсвэл шалгалт нэмэгдэх
 * үед л ажилладаг. Дүрэм өөрчлөгдөхөөс өмнө татгалзуулсан мөрүүд хуучин
 * дүнтэйгээ үлдэнэ:
 *
 *      төлөвлөсөн 100 · мэдээлсэн 100 (хуучин) · батлагдсан 0
 *      → үлдэгдэл 0 → «үлдэгдлээс бага байх ёстой» гэж хаагдана
 *
 * Тиймээс гүйцэтгэгч засвараа огт илгээж чадахгүй хэвээр үлдэнэ. Кодын
 * засвар нь өгөгдлийг өөрөө засдаггүй — энэ миграц түүнийг гүйцээнэ.
 *
 * ЗӨВХӨН татгалзсан шалгалттай мөрийг хөндөнө: бусад мөрийн дүн өөрчлөгдөх
 * шалтгаангүй бөгөөд 75 барилга × 3,290 мөрийг бүгдийг нь дахин бодох нь
 * шаардлагагүй удаан.
 */
return new class extends Migration
{
    public function up(): void
    {
        $affected = DB::table('work_items')
            ->whereIn('id', function ($query) {
                $query->select('work_item_id')
                    ->from('inspections')
                    ->where('rejected_qty', '>', 0);
            })
            ->pluck('id');

        WorkItem::whereIn('id', $affected)
            ->chunkById(200, function ($items) {
                foreach ($items as $item) {
                    $item->recalculate();
                }
            });
    }

    public function down(): void
    {
        // Дахин тооцоолол нь мэдээллийг устгадаггүй — буцаах зүйлгүй.
    }
};
