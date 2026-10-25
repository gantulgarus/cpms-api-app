<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Шалгалт — хоёр шаттай каскад.
 *
 *     ТГ илгээх → ТАЛБАЙН инженер шалгах → ХЯНАЛТЫН инженер батлах
 *
 * v1-д баталгаажуулалт нь тайлбар доторх `[БАТЛАВ]` текст байсан бөгөөд
 * гүйцэтгэгч өөрөө бичээд өөрийгөө баталж чаддаг байсан. Энэ хүснэгт түүнийг
 * орлоно.
 *
 * ШАТ БҮР ӨӨРИЙН САНГААС ИДНЭ. Энэ нь хамгийн чухал дүрэм:
 *
 *     1-р шат:  мэдээлсэн − шалгасан   (`reported_qty − checked_qty`)
 *     2-р шат:  шалгасан  − баталсан   (`checked_qty − accepted_qty`)
 *
 * Урьд нь хоёулаа `reported − accepted`-ээс иддэг байсан тул талбайн инженер
 * шалгамагц хяналтын инженерт батлах юу ч үлдэхгүй, актад орох хэмжээ 0
 * болдог байв. Яг тэр шалтгаанаар шат нэг болгож хасагдсан.
 */
class Inspection extends Model
{
    use HasUuids;

    /** 1-р шат — талбайн инженер шалгана, тоог засаж болно. */
    public const STAGE_SITE = 'site_check';

    /** 2-р шат — хяналтын инженер батална. ЗӨВХӨН энэ шат актад орно. */
    public const STAGE_FINAL = 'final_approval';

    public const STAGES = [self::STAGE_SITE, self::STAGE_FINAL];

    public const STAGE_LABELS = [
        self::STAGE_SITE => 'Талбайн инженер шалгасан',
        self::STAGE_FINAL => 'Хяналтын инженер баталсан',
    ];

    public const RESULTS = ['accepted', 'rejected', 'partial'];

    /**
     * Шатыг хийх эрхтэй үүргүүд.
     *
     * 1-р шат нь АГУУЛАХ ёсоор өргөн: талбайн инженер өөрөө шалгана, дээд
     * шатныхан ч хийж чадна (талбайн инженер амралттай үед ажил гацах
     * ёсгүй). 2-р шат нь зөвхөн батлах эрхтэй хүн.
     *
     * `contractor` ХАРИН ХОЁУЛАНД Ч БАЙХГҮЙ: гүйцэтгэгч өөрийгөө баталж
     * чаддаг байсан нь v1-ийн гол гомдол.
     */
    public const STAGE_ROLES = [
        /*
         * ЗАХИРАЛ ХОЁР ШАТАНД Ч ОРООГҮЙ.
         *
         * Удирдлагын эрхийг «бүх зүйлд хүрнэ» гэж ойлговол хяналт утгагүй
         * болно: хэн ч захирлаар батлуулж, «мэдээлэгч ба батлагч өөр хүн»
         * гэсэн дүрмийг тойрч болно.
         */
        self::STAGE_SITE => ['site_engineer', 'inspector', 'general_engineer', 'project_manager', 'admin'],
        self::STAGE_FINAL => ['inspector', 'general_engineer', 'admin'],
    ];

    protected $fillable = [
        'work_item_id', 'inspector_id', 'stage', 'result',
        // `rework_work_item_id` нь ХУУЧИРСАН: татгалзсан хэмжээ мэдээлсэн
        // дүнгээс хасагдаж, засвар нь ижил мөрөн дээр бүртгэгддэг болсон тул
        // тусдаа «дахин хийх» ажил үүсгэхээ больсон. Багана нь хуучин
        // өгөгдлийн төлөө үлдсэн.
        'accepted_qty', 'rejected_qty', 'reason', 'rework_work_item_id', 'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_qty' => 'decimal:3',
            'rejected_qty' => 'decimal:3',
            'inspected_at' => 'datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /** Хүний уншиж болох шатны нэр. */
    public function stageLabel(): string
    {
        return self::STAGE_LABELS[$this->stage] ?? $this->stage;
    }

    /** Энэ шалгалтад бөглөсөн чанарын checklist. */
    public function checklistAnswers(): HasMany
    {
        return $this->hasMany(InspectionChecklistAnswer::class);
    }
}
