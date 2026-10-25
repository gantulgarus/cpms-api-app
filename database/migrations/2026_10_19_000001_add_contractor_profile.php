<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Гүйцэтгэгчийн гэрээний мэдээлэл.
 *
 * ЯАГААД ХЭРЭГТЭЙ ВЭ: «Ажил гүйцэтгэх тохиролцооны хуудас» нь РД, дансны
 * дугаар, банкийг шаарддаг. Эдгээргүйгээр тохиролцоо бичигдэхгүй, акт
 * хэвлэгдэхгүй, төлбөр гарахгүй. Одоо системд зөвхөн нэр, утас байна.
 *
 * РД нь ДАВХАРДАХГҮЙ: ижил компани хоёр мөр болбол акт, барьцаа, торгууль
 * хоёр хуваагдаж, нийт тооцоо бүрэн болохгүй. Нэрээр давхардлыг барих нь
 * хүрэлцэхгүй — «Бетон И дезайн» ба «Бетон И Дезайн» хоёр өөр мөр болдог
 * (бодит хуваарьт яг тэр байв).
 *
 * Хоосон байж болно: одоо байгаа 21 гүйцэтгэгчийн РД нэг ч файлд байхгүй,
 * захиалагчаас авч нөхнө. Тиймээс `unique` нь NULL-ийг хязгаарлахгүй.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            // Татвар төлөгчийн дугаар — 7 орон. Хэлбэрийг FormRequest шалгана.
            $table->string('registration_no', 20)->nullable()->after('company_name');
            $table->string('bank_name', 100)->nullable()->after('email');
            $table->string('bank_account', 50)->nullable()->after('bank_name');

            /*
             * Ажлын ангилал — `trade_specialty` нь ЧӨЛӨӨТ текст, хайлтад
             * тохиромжтой ч эрэмбэлэх, шүүхэд тохиромжгүй. Ангилал нь
             * лавлахаас гарна.
             */
            $table->string('category', 40)->nullable()->after('trade_specialty');

            /*
             * Төлөв — гэрээ цуцлагдсан, хар списктэй гүйцэтгэгчид шинэ ажил
             * оноогдох ёсгүй. Устгахгүй: түүх, акт, барьцаа нь үлдэнэ.
             */
            $table->string('status', 20)->default('active')->after('category');

            $table->unique('registration_no');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('contractors', function (Blueprint $table) {
            $table->dropUnique(['registration_no']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'registration_no', 'bank_name', 'bank_account', 'category', 'status',
            ]);
        });
    }
};
