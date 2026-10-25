<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ажлын нэгж — ажлын төрөл × байршлын огтлолцол.
 *
 * Нэг 16 давхрын блокт ~3,290 мөр байна. Тиймээс энэ модель дээрх бүх зүйл
 * жагсаалтын гүйцэтгэлээр хэмжигдэнэ: хуримтлагдсан дүн энд хадгалагдаж,
 * харилцаа нь eager load-оор татагдана.
 */
class WorkItem extends Model
{
    use HasFactory;
    use HasUuids;

    /**
     * Хяналтын төлвүүд.
     *
     * Нэг газар бичигдэнэ, дараа нь SQL хэсгүүд эндээс гарна. Урьд нь
     * «pending» гэсэн мөр самбар, дараалал, блокийн нэгтгэл гурван файлд
     * тусад нь бичигдсэн байсан тул нэг нь зөрөхөд блокийн хуудас 11%,
     * самбар 3% гэж ХАРИЛЦАН ЗӨРӨХ тоо харуулдаг байв.
     */
    public const REVIEW_STATES = [
        'none', 'pending_check', 'pending_approval',
        'returned_by_site', 'returned_by_final', 'approved',
    ];

    /** Хэн нэгнээс үйлдэл хүлээж буй төлвүүд. */
    public const PENDING_STATES = ['pending_check', 'pending_approval'];

    /** Буцаагдсан — ТГ дахин хийх шаардлагатай. */
    public const RETURNED_STATES = ['returned_by_site', 'returned_by_final'];

    /**
     * Бутархай тооны харьцуулалтын хүлцэл.
     *
     * Тоо хэмжээ нь мянгатын орноор хадгалагддаг тул 0.0005 нь хагас орон —
     * түүнээс бага зөрүү нь бөөрөнхийллийн үлдэгдэл, бодит хүлээгдэл биш.
     */
    private const EPSILON = 0.0005;

    protected $fillable = [
        'block_id', 'location_id', 'work_type_id', 'contractor_id', 'inspector_id',
        'code', 'name', 'unit',
        'planned_qty', 'budget_unit_price',
        'reported_qty', 'checked_qty', 'accepted_qty',
        'planned_start_date', 'planned_end_date', 'baseline_end_date',
        'status', 'review_state', 'rework_of_id',
    ];

    protected function casts(): array
    {
        return [
            'planned_qty' => 'decimal:3',
            'budget_unit_price' => 'decimal:2',
            'reported_qty' => 'decimal:3',
            'checked_qty' => 'decimal:3',
            'accepted_qty' => 'decimal:3',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
            'baseline_end_date' => 'date',
        ];
    }

    /**
     * Үндсэн огноог ҮҮСГЭХ үед автоматаар тавина.
     *
     * `planned_end_date` нь ApplyBlockDesign, импорт, гараар нэмэх,
     * такт хуваарь гэсэн дөрвөн замаар тавигддаг. Тэр дөрвийг тусад нь
     * засвал нэг нь хоцорч, `baseline_end_date` хоосон үлдэнэ — тэгвэл
     * гулсалт хэмжигдэхгүй болно. Тиймээс НЭГ газар.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item) {
            $item->baseline_end_date ??= $item->planned_end_date;
        });
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * Хяналт шалгалтыг хариуцах инженер — төсвөөс оноогддог.
     *
     * Гүйцэтгэлийг ХЭН БАТАЛСАН нь `inspectionRequests`-д бичигддэг. Энэ нь
     * «хэн батлах ёстой» гэсэн төлөвлөгөө.
     */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function extensionRequests(): HasMany
    {
        return $this->hasMany(ExtensionRequest::class);
    }

    public function progressEntries(): HasMany
    {
        return $this->hasMany(ProgressEntry::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }


    // -----------------------------------------------------------------
    // Тооцоолсон утгууд — client тал дахин бодохгүй
    // -----------------------------------------------------------------

    /** Үлдэгдэл нь БАТЛАГДСАН тоо хэмжээнээс бодогдоно, мэдээлсэнээс биш. */
    public function getRemainingQtyAttribute(): float
    {
        return round(max((float) $this->planned_qty - (float) $this->accepted_qty, 0), 3);
    }

    /**
     * Нэг ажлын явцыг 0..1 болгон гаргах SQL илэрхийлэл.
     *
     * ЯАГААД НЭГ ГАЗАР ВЭ: хянах самбар ба блокийн нэгтгэл хоёр тусдаа
     * `GROUP BY` query бичдэг. Томьёог хоёр газар давхардуулбал нэгийг нь
     * өөрчлөхөд нөгөө нь хоцорно — яг тэр учраас А блок самбар дээр 3%,
     * дотор нь ороход 11% гэж харагдаж байв. Хэрэглэгч алинд нь итгэхээ
     * мэдэхгүй бол хоёулаа хэрэггүй.
     *
     * `least()` нь SQLite-д байхгүй тул CASE-ээр бичив.
     *
     * @param  string  $alias  Query доторх хүснэгтийн алиас (ж: `wi`).
     */
    public static function progressSql(string $alias = 'work_items'): string
    {
        /*
         * `* 1.0` нь ЗААВАЛ — SQLite дээр БҮХЭЛ ТООН ХУВААЛТ хийгддэг.
         *
         * `324 / 648` нь SQLite-д 0 гарна: баганын NUMERIC affinity нь
         * бүхэл утгыг INTEGER болгож хадгалдаг тул хуваалт нь бүхлээр явна.
         * Үүний улмаас ТООЛЖ БОЛОХУЙЦ тоо хэмжээтэй (648 м², 324 батлагдсан)
         * ажил бүр 0% гэж бодогдож, хагас дууссан төсөл самбар дээр 43%
         * гэж харагдаж байв — зөв нь 46%.
         *
         * Бутархай тоотой мөрүүд (5.5 / 11) зөв бодогддог байсан тул алдаа
         * нь ЗАРИМ мөрөнд л гарч, нийт дүн бага зэрэг хазайх хэлбэрээр
         * нуугдаж байсан.
         */
        return "case
            when {$alias}.planned_qty <= 0 then 0
            when {$alias}.accepted_qty >= {$alias}.planned_qty then 1.0
            else ({$alias}.accepted_qty * 1.0) / {$alias}.planned_qty
        end";
    }

    /**
     * Энэ мөр ХЭМЖИГДЭХ үү — төлөвлөгөөт тоо хэмжээтэй юу.
     *
     * ЯАГААД ХЭРЭГТЭЙ ВЭ: тоо хэмжээ нь тодорхойгүй ажлын төрөл цөөнгүй
     * (хавсралт-2-ын 47-оос 30). Тэдгээр мөр «0% хийгдсэн» БИШ, харин
     * «хэмжих боломжгүй». Хоёуланг нь ижил гэж үзвэл хувь нь мөнхөд доогуур
     * гарч, тоо хэмжээ нь бүртгэгдэх хүртэл хэзээ ч зөв болохгүй.
     *
     * Тиймээс хувийг ЭДГЭЭР мөрүүдийн дунд бодно, бүх мөрийн дунд биш.
     */
    public static function measurableSql(string $alias = 'work_items'): string
    {
        return "case when {$alias}.planned_qty > 0 then 1 else 0 end";
    }

    /**
     * Энэ мөр ХАРИУЦАГЧГҮЙ юу.
     *
     * Загвар буулгахад ажил үргэлж хариуцагчгүй үүсдэг — аль компани юу
     * хийхийг зураг төсөл мэддэггүй, тэр нь гэрээний асуудал. Гэвч оноохоо
     * мартвал туслан гүйцэтгэгч нэвтрээд ЮУ Ч ХАРАХГҮЙ: хамрах хүрээ нь
     * `contractor_id`-аар шүүдэг тул «хэнийх ч биш» ажил хэнд ч харагдахгүй.
     *
     * Тиймээс энэ тоог нуухгүй, самбар дээр ил гаргана.
     */
    /**
     * «Хүлээгдэж байна» SQL.
     *
     * Хоёр шат болсон тул `review_state = 'pending'` гэсэн шалгалт бүр ҮНЭН
     * ЧАНАРААРАА ХООСОН болсон — тэр утга дахиж бичигдэхгүй. Самбар,
     * дараалал, нэгтгэл бүгд эндээс уншина.
     */
    public static function pendingSql(string $alias = 'work_items'): string
    {
        return self::inSql($alias, self::PENDING_STATES);
    }

    /** «Буцаагдсан» SQL — хоёр буцаах цэгийг нэгтгэнэ. */
    public static function returnedSql(string $alias = 'work_items'): string
    {
        return self::inSql($alias, self::RETURNED_STATES);
    }

    /**
     * @param  array<int, string>  $states
     */
    private static function inSql(string $alias, array $states): string
    {
        $list = implode(', ', array_map(fn ($s) => "'{$s}'", $states));

        return "case when {$alias}.review_state in ({$list}) then 1 else 0 end";
    }

    public static function unassignedSql(string $alias = 'work_items'): string
    {
        return "case when {$alias}.contractor_id is null then 1 else 0 end";
    }

    /** Хэдэн хувь батлагдсан бэ — ГАНЦ мөрийн хувьд (нэгж холилдохгүй). */
    public function getPercentageAttribute(): int
    {
        $planned = (float) $this->planned_qty;

        return $planned > 0 ? (int) round((float) $this->accepted_qty / $planned * 100) : 0;
    }

    public function getOverdueDaysAttribute(): int
    {
        return $this->overdueAgainst($this->planned_end_date);
    }

    /**
     * ҮНДСЭН төлөвлөгөөнөөс хэр гулссан.
     *
     * `overdueDays` нь ӨНӨӨДРИЙН амлалтаас хоцорсон эсэхийг хэлнэ — сунгалт
     * батлагдах бүрд тэр тоо тэг болно. Тиймээс дангаараа авбал «хэн ч
     * хэзээ ч хоцроогүй» гэсэн зураг гарна.
     *
     * Энэ тоо нь сунгалтаар ХӨДӨЛДӨГГҮЙ: хуваарийн жинхэнэ гулсалт.
     */
    public function getBaselineOverdueDaysAttribute(): int
    {
        return $this->overdueAgainst($this->baseline_end_date ?? $this->planned_end_date);
    }

    /** Батлагдсан сунгалтаар нийт хэдэн хоног хойшилсон. */
    public function getExtendedDaysAttribute(): int
    {
        if (! $this->baseline_end_date || ! $this->planned_end_date) {
            return 0;
        }

        $days = (int) $this->baseline_end_date->diffInDays($this->planned_end_date, false);

        return max($days, 0);
    }

    private function overdueAgainst(?\Carbon\CarbonInterface $date): int
    {
        if ($this->status === 'completed' || ! $date) {
            return 0;
        }

        $days = $date->diffInDays(now(), false);

        return $days > 0 ? (int) $days : 0;
    }

    /**
     * Хуримтлагдсан дүнг дэд хүснэгтээс дахин бодож хадгална.
     *
     * Явц эсвэл шалгалт нэмэгдэх бүрд transaction дотор дуудагдана. Жагсаалт
     * харах бүрд бодохгүй — тэр нь 3,290 мөр дээр N+1 болно.
     */
    public function recalculate(): void
    {
        /*
         * МЭДЭЭЛСЭН = мэдээлсэн нийт − ТАТГАЛЗСАН.
         *
         * ЯАГААД ХАСДАГ ВЭ: татгалзсан ажил хийгдээгүйтэй адил. Хасахгүй бол
         * 100 м² мэдээлээд бүхэлд нь татгалзуулсан ажил дээр «үлдэгдэл 0»
         * гэж гарч, гүйцэтгэгч засвараа ДАХИН ИЛГЭЭЖ ЧАДАХГҮЙ болно.
         *
         * Ингэснээр нэг физик ажил = НЭГ мөр. Татгалзсан бүрд «дахин хийх»
         * гэсэн шинэ мөр үүсгэвэл блокийн төлөвлөгөөт тоо хэмжээ хоёр дахин
         * болж, хувь бодолт худал болно — 100 м²-ийн ажил 200 м² болж
         * харагдана.
         */
        $reportedGross = (float) $this->progressEntries()->sum('completed_qty');
        $rejected = (float) $this->inspections()->sum('rejected_qty');
        $planned = (float) $this->planned_qty;

        /*
         * Шат бүрийн хэмжээг ТУСАД НЬ нэгтгэнэ.
         *
         * Хамгийн чухал мөр. Урьд нь `sum('accepted_qty')` гэж бүх шатыг
         * хольж нэгтгэдэг байсан тул талбайн инженер 100 м² шалгахад
         * `accepted_qty` 100 болж, хяналтын инженерт батлах юу ч үлдэхгүй,
         * актад орох хэмжээ 0 болдог байв — шат хасагдсан шалтгаан.
         */
        $checked = (float) $this->inspections()
            ->where('stage', Inspection::STAGE_SITE)->sum('accepted_qty');
        $accepted = (float) $this->inspections()
            ->where('stage', Inspection::STAGE_FINAL)->sum('accepted_qty');

        $this->reported_qty = round(max($reportedGross - $rejected, 0), 3);
        $this->checked_qty = round(min($checked, (float) $this->reported_qty), 3);
        $this->accepted_qty = round(min($accepted, $planned), 3);

        $this->status = match (true) {
            $this->accepted_qty >= $planned && $planned > 0 => 'completed',
            $reportedGross > 0 => 'in_progress',
            default => 'not_started',
        };

        $this->review_state = $this->resolveReviewState();

        $this->save();
    }

    /**
     * Хяналтын төлөв — ХЭН рүү бөмбөг байгааг хэлнэ.
     *
     * Зургаан төлөв, хоёр буцаах цэг:
     *
     *     none              · мэдээлсэн юм алга
     *     pending_check     · талбайн инженер шалгахыг хүлээж байна   (1-р шат)
     *     pending_approval  · хяналтын инженер батлахыг хүлээж байна  (2-р шат)
     *     returned_by_site  · талбайн инженер буцаасан → ТГ дахин хийнэ
     *     returned_by_final · хяналтын инженер буцаасан → ТГ дахин хийнэ
     *     approved          · бүрэн батлагдсан
     *
     * ЯАГААД БУЦААХ ХОЁР ТӨЛӨВ ВЭ: нэг `returned`-ээр нийлүүлбэл «хэн
     * буцаасан» гэдэг нь зөвхөн шалгалтын түүхэнд үлдэнэ. Гүйцэтгэгчийн
     * дэлгэц дээр «талбайн инженер зураасаа засуулахаар буцаасан» ба
     * «хяналтын инженер даац хүрэхгүй гэж буцаасан» хоёр нь ТЭС ӨӨР ажил —
     * тусад нь тоологдох ёстой.
     */
    private function resolveReviewState(): string
    {
        $stages = $this->workType?->inspection_stages ?? 2;

        $reported = (float) $this->reported_qty;
        $checked = (float) $this->checked_qty;
        $accepted = (float) $this->accepted_qty;

        /*
         * НЭГ ШАТТАЙ ажилд 1-р шат АЛГАСНА: шалгасан хэмжээг мэдээлсэнтэй
         * тэнцүү гэж үзнэ. Үүнгүйгээр `pending_check` төлөвт мөнхөд гацна —
         * тэр шатыг хийх дэлгэц ч байхгүй.
         */
        if ($stages < 2) {
            $checked = $reported;
        }

        $awaitingCheck = $checked < $reported - self::EPSILON;
        $awaitingApproval = $accepted < $checked - self::EPSILON;

        /*
         * Буцаасан эсэхийг ХЯНАГДААГҮЙ хэмжээ үлдээгүй үед л хардаг.
         *
         * Гүйцэтгэгч засвараа мэдээлмэгц хүлээгдэх хэмжээ гарч ирэх тул
         * төлөв нь өөрөө дараалалд ЭРГЭЖ ОРНО. Урьд нь зөвхөн сүүлийн
         * шалгалтыг хардаг байсан тул нэг удаа буцаагдсан ажил мөнхөд
         * гацдаг байв.
         */
        if ($awaitingCheck) {
            return 'pending_check';
        }

        if ($awaitingApproval) {
            return 'pending_approval';
        }

        $latest = $this->inspections()->latest('inspected_at')->first();

        if ((float) ($latest?->rejected_qty ?? 0) > 0) {
            return $latest->stage === Inspection::STAGE_SITE
                ? 'returned_by_site'
                : 'returned_by_final';
        }

        return $reported <= 0 ? 'none' : 'approved';
    }

    /**
     * Дараагийн үйлдэл ямар шатад байна — `null` бол хүлээх юм алга.
     *
     * Төлөвөөс гарна, тусад нь бодохгүй: хоёр логик салбарлавал дэлгэц
     * «батлах» товч харуулаад сервер «шалгах шат хүлээж байна» гэж
     * татгалзах эрсдэлтэй.
     */
    public function nextStage(): ?string
    {
        return match ($this->review_state) {
            'pending_check' => Inspection::STAGE_SITE,
            'pending_approval' => Inspection::STAGE_FINAL,
            default => null,
        };
    }

    /**
     * Шатны «сан» — тэр шатаар дамжуулж болох хэмжээ.
     *
     * Валидаци ба дэлгэц ХОЁУЛАА эндээс уншина: хоёр газар тусад нь бодвол
     * дэлгэц «батлах боломжтой» гэж харуулаад сервер татгалзах эрсдэлтэй.
     */
    public function stagePool(string $stage): float
    {
        $single = ($this->workType?->inspection_stages ?? 2) < 2;

        if ($stage === Inspection::STAGE_SITE) {
            return round((float) $this->reported_qty - (float) $this->checked_qty, 3);
        }

        // Нэг шаттай ажилд 2-р шат нь МЭДЭЭЛСЭНЭЭС шууд иднэ.
        $source = $single ? (float) $this->reported_qty : (float) $this->checked_qty;

        return round($source - (float) $this->accepted_qty, 3);
    }
}
