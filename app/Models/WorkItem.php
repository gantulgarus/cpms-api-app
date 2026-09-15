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

    protected $fillable = [
        'block_id', 'location_id', 'work_type_id', 'contractor_id',
        'code', 'name', 'unit',
        'planned_qty', 'reported_qty', 'accepted_qty',
        'planned_start_date', 'planned_end_date',
        'status', 'review_state', 'rework_of_id',
    ];

    protected function casts(): array
    {
        return [
            'planned_qty' => 'decimal:3',
            'reported_qty' => 'decimal:3',
            'accepted_qty' => 'decimal:3',
            'planned_start_date' => 'date',
            'planned_end_date' => 'date',
        ];
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
        return "case
            when {$alias}.planned_qty <= 0 then 0
            when {$alias}.accepted_qty >= {$alias}.planned_qty then 1.0
            else {$alias}.accepted_qty / {$alias}.planned_qty
        end";
    }

    /** Хэдэн хувь батлагдсан бэ — ГАНЦ мөрийн хувьд (нэгж холилдохгүй). */
    public function getPercentageAttribute(): int
    {
        $planned = (float) $this->planned_qty;

        return $planned > 0 ? (int) round((float) $this->accepted_qty / $planned * 100) : 0;
    }

    public function getOverdueDaysAttribute(): int
    {
        if ($this->status === 'completed' || ! $this->planned_end_date) {
            return 0;
        }

        $days = $this->planned_end_date->diffInDays(now(), false);

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
        $accepted = (float) $this->inspections()->sum('accepted_qty');
        $planned = (float) $this->planned_qty;

        $this->reported_qty = round(max($reportedGross - $rejected, 0), 3);
        $this->accepted_qty = round(min($accepted, $planned), 3);

        $this->status = match (true) {
            $this->accepted_qty >= $planned && $planned > 0 => 'completed',
            $reportedGross > 0 => 'in_progress',
            default => 'not_started',
        };

        $latest = $this->inspections()->latest('inspected_at')->first();
        $pending = (float) $this->reported_qty - (float) $this->accepted_qty;

        /*
         * `returned` = «хийсэн зүйл татгалзсан, дахин хийх шаардлагатай».
         *
         * Гүйцэтгэгч засвараа мэдээлмэгц хүлээгдэж буй хэмжээ гарч ирэх тул
         * төлөв нь өөрөө `pending` болж, хяналтын инженерийн дараалалд ЭРГЭЖ
         * ОРНО. Урьд нь зөвхөн сүүлийн шалгалтыг хардаг байсан тул нэг удаа
         * буцаагдсан ажил мөнхөд гацдаг байв.
         */
        $this->review_state = match (true) {
            $pending > 0.0005 => 'pending',
            (float) ($latest?->rejected_qty ?? 0) > 0 => 'returned',
            $this->reported_qty <= 0 => 'none',
            default => 'approved',
        };

        $this->save();
    }
}
