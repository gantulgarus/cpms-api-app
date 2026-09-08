<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Хэрэглэгч ЮУ харахыг тодорхойлох НЭГ дүрэм.
 *
 * Хоёр өөр хамрах хүрээ байна:
 *
 *  - Талбайн инженер БЛОКоор хязгаарлагдана (`scope_block_ids`) — тэр 1–2
 *    барилга хариуцаж, тэр барилгын бүх ажлыг хардаг.
 *  - Туслан гүйцэтгэгч ГҮЙЦЭТГЭГЧээр хязгаарлагдана. "Гоо Засал ХХК" 49
 *    барилгад дотор засал хийдэг тул блокоор хязгаарлах нь буруу; харин өөр
 *    гүйцэтгэгчийн сантехникийн ажлыг хараах ёсгүй.
 *
 * Яагаад тусдаа класс: ижил дүрэм жагсаалт, нэгтгэл, блокийн жагсаалт,
 * dashboard, middleware гэсэн 5 газар хэрэгтэй. Тус тусад бичвэл заавал
 * зөрнө — тэгэхэд нэгтгэл "204 ажил" гэж бичээд, дарахад 12 гарч ирнэ.
 * (Ийм зөрүү бид нэг удаа аваад үзсэн: блокийн үндэс зангилааны шүүлтүүр.)
 */
class WorkScope
{
    /** WorkItem жагсаалтад (Eloquent). */
    public function workItems(EloquentBuilder $query, ?User $user): EloquentBuilder
    {
        if ($user?->isContractorRep()) {
            $query->where('contractor_id', $user->contractor_id);
        }

        return $query;
    }

    /** Нэгтгэлийн түүхий query-д. Alias-ыг дуудагч мэднэ. */
    public function rawWorkItems(QueryBuilder $query, ?User $user, string $alias = 'wi'): QueryBuilder
    {
        if ($user?->isContractorRep()) {
            $query->where("{$alias}.contractor_id", $user->contractor_id);
        }

        return $query;
    }

    /**
     * Блокийн жагсаалтад.
     *
     * Гүйцэтгэгчид зөвхөн ажил байгаа барилга харагдана — хоосон 74 барилгын
     * жагсаалт хөтлөөд явах шаардлагагүй.
     */
    public function blocks(EloquentBuilder $query, ?User $user): EloquentBuilder
    {
        if (! $user) {
            return $query;
        }

        if ($user->isContractorRep()) {
            return $query->whereIn(
                'id',
                WorkItem::query()->where('contractor_id', $user->contractor_id)->select('block_id')
            );
        }

        // Талбайн инженерт харах эрхгүй блокийг жагсаалтад ч гаргахгүй — эс
        // бөгөөс дарахад 403 авах "хуурамч" мөр болно.
        if (! $user->canSeeAllBlocks()) {
            return $query->whereIn('id', $user->scope_block_ids ?? []);
        }

        return $query;
    }

    /** Тухайн нэг ажлыг харах/бүртгэх эрхтэй эсэх. */
    public function allows(?User $user, WorkItem $workItem): bool
    {
        if (! $user?->isContractorRep()) {
            return true;
        }

        return $workItem->contractor_id !== null
            && $workItem->contractor_id === $user->contractor_id;
    }
}
