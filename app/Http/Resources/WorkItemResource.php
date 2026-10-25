<?php

namespace App\Http\Resources;

use App\Services\HoldPointGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Тооцоолсон утга бүр СЕРВЕРЭЭС ирнэ — `remainingQty`, `percentage`,
 * `overdueDays` зэргийг client дахин бодохгүй.
 */
class WorkItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'blockId' => $this->block_id,
            /*
             * Блокийн НЭР — дарааллын дэлгэцэд зайлшгүй.
             *
             * Олон блокийн ажил нэг жагсаалтад холилдоход «аль барилгынх
             * вэ» гэдэг нь харцаар ялгагдах ёстой. `blockId` нь UUID тул
             * хүнд юу ч хэлэхгүй.
             */
            'blockName' => $this->whenLoaded('block', fn () => $this->block?->name),
            'locationId' => $this->location_id,
            'workTypeId' => $this->work_type_id,
            'code' => $this->code,
            'name' => $this->name,
            'unit' => $this->unit,

            'plannedQty' => (float) $this->planned_qty,
            'reportedQty' => (float) $this->reported_qty,
            /*
             * 1-р шатаар шалгагдсан хэмжээ — мэдээлсэн ба баталсан хоёрын
             * ХООРОНДОХ сан. Дэлгэц үүнийг харуулахгүй бол «40 мэдээлсэн,
             * 10 баталсан» гэж хоёр тоо харагдаад, дунд нь 30 хаана байгаа
             * нь ойлгомжгүй болно.
             */
            'checkedQty' => (float) $this->checked_qty,
            'acceptedQty' => (float) $this->accepted_qty,
            'remainingQty' => $this->remaining_qty,
            'percentage' => $this->percentage,

            'plannedStartDate' => $this->planned_start_date?->toDateString(),
            'plannedEndDate' => $this->planned_end_date?->toDateString(),
            'status' => $this->status,
            'reviewState' => $this->review_state,

            /*
             * Дараагийн үйлдэл нь ЯМАР шатад вэ, хэдэн нэгж хүлээж байна.
             *
             * Дэлгэц хоёр шатны сангаа дахин бодохгүй: бодвол сервертэй
             * зөрөөд «батлах боломжтой» гэж харуулсан товч 422 авна.
             */
            'inspectionStages' => (int) ($this->whenLoaded('workType',
                fn () => $this->workType->inspection_stages, 2)),
            'nextStage' => $this->nextStage(),

            /*
             * Шалгалтын цэгийн хориг — ДЭЛГЭЦ УРЬДЧИЛЖ харуулна.
             *
             * Хүн тоо бичээд «Илгээх» дарж байж 422 авах нь муу: тэр
             * үед гүйцэтгэгч аль хэдийн ажлаа хийчихсэн байна. Хориг нь
             * товчийг дарахаас ӨМНӨ харагдах ёстой.
             *
             * Зөвхөн дэлгэрэнгүй хуудсанд бодно. Жагсаалтад оруулбал 200
             * мөр тутамд нэмэлт query явж N+1 болно — тиймээс контроллер
             * ИЛЭРХИЙ асуух ёстой, холбоос ачаалагдсан эсэхээр таамаглахгүй.
             */
            'holdPointReason' => $this->when(
                (bool) $this->resource->getAttribute('with_hold_point'),
                fn () => app(HoldPointGate::class)->reason($this->resource),
            ),
            'stagePool' => $this->nextStage() ? $this->stagePool($this->nextStage()) : 0.0,
            /*
             * ҮНДСЭН төлөвлөгөө — сунгалтаар ХӨДӨЛДӨГГҮЙ.
             *
             * `overdueDays` нь өнөөдрийн амлалтаас хоцорсон эсэхийг хэлнэ:
             * сунгалт батлагдах бүрд тэг болно. Дангаараа авбал «хэн ч
             * хэзээ ч хоцроогүй» гэсэн зураг гарна.
             */
            'baselineEndDate' => $this->baseline_end_date?->toDateString(),
            'baselineOverdueDays' => $this->baseline_overdue_days,
            'extendedDays' => $this->extended_days,

            'overdueDays' => $this->overdue_days,

            // Дахин хийсэн нийт хэмжээ — татгалзсан бүх шалгалтын нийлбэр.
            // Зөвхөн дэлгэрэнгүй хуудсанд ачаалагдана (жагсаалтад N+1 болно).
            'rejectedTotal' => $this->when(
                $this->inspections_sum_rejected_qty !== null,
                fn () => (float) $this->inspections_sum_rejected_qty,
            ),

            'location' => $this->whenLoaded('location', fn () => [
                'id' => $this->location->id,
                'path' => $this->location->path,
                'level' => $this->location->level,
            ]),
            'workType' => $this->whenLoaded('workType', fn () => [
                'id' => $this->workType->id,
                'name' => $this->workType->name,
                'groupName' => $this->workType->group?->name,
            ]),
            'contractor' => $this->whenLoaded('contractor', fn () => $this->contractor ? [
                'id' => $this->contractor->id,
                'name' => $this->contractor->name,
            ] : null),

            /*
             * Хяналт шалгалтыг хариуцах инженер — төсвөөс оноогддог.
             *
             * `position` ч хамт явна: ижил `inspector` үүрэгтэй дөрвөн хүнийг
             * дэлгэц дээр ялгах өөр мэдээлэл байхгүй.
             */
            'inspector' => $this->whenLoaded('inspector', fn () => $this->inspector ? [
                'id' => $this->inspector->id,
                'name' => $this->inspector->name,
                'position' => $this->inspector->position,
            ] : null),
        ];
    }
}
