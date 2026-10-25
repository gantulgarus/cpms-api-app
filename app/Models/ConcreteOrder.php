<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Бетон зуурмагийн захиалга.
 */
class ConcreteOrder extends Model
{
    use HasUuids;

    /**
     * ТӨСӨВ ИДДЭГ төлөвүүд.
     *
     * Драфт нь иддэггүй — бичиж байгаа захиалга нь амлалт биш. Цуцлагдсан
     * нь төсвийг суллана. Бусад бүгд үлдэгдлээс хасагдана: илгээсэн
     * захиалгыг «болоогүй байна» гэж үзвэл хоёр инженер нэг л давхрын
     * бетоныг хоёр удаа захиалж болно.
     */
    public const COMMITTED_STATES = ['submitted', 'confirmed', 'delivered'];

    public const STATUS_LABELS = [
        'draft' => 'Драфт',
        'submitted' => 'Илгээсэн',
        'confirmed' => 'Баталгаажсан',
        'delivered' => 'Нийлүүлэгдсэн',
        'cancelled' => 'Цуцалсан',
    ];

    /**
     * Захиалга БИЧИХ эрхтэй үүргүүд.
     *
     * Талбайн инженер орно — цутгалт зохион байгуулдаг хүн тэр. Гүйцэтгэгч
     * ОРОХГҮЙ: бетон бол захиалагчийн зардал.
     */
    public const REQUESTER_ROLES = [
        'site_engineer', 'project_manager', 'general_engineer', 'admin',
    ];

    /**
     * БАТАЛГААЖУУЛАХ эрхтэй үүргүүд.
     *
     * Бланк дээр «Захиалагч талын хяналтын инженер» гэж бичигдсэн.
     * Захиалсан хүн өөрөө баталгаажуулж БОЛОХГҮЙ — энэ нь мөнгө зарлага.
     */
    public const CONFIRMER_ROLES = ['inspector', 'general_engineer', 'admin'];

    /**
     * Бланк дээрх анхааруулга: «Бетон зуурмаг авахаас 3 хоногийн өмнө
     * захиалга өгнө».
     */
    public const LEAD_DAYS = 3;

    protected $fillable = [
        'number', 'block_id', 'location_id', 'material_id',
        'pour_date', 'pour_half', 'pour_time', 'structure',
        'grade', 'concrete_class', 'slump_cm', 'quantity', 'needs_pump',
        'status', 'delivered_qty', 'delivered_at', 'notes',
        'requested_by', 'received_by', 'confirmed_by', 'submitted_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'pour_date' => 'date',
            'delivered_at' => 'date',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'quantity' => 'float',
            'delivered_qty' => 'float',
            'needs_pump' => 'boolean',
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

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Төсвөөс хасагдаж буй захиалгууд. */
    public function scopeCommitted(Builder $query): Builder
    {
        return $query->whereIn('status', self::COMMITTED_STATES);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Төсвөөс хасагдах хэмжээ.
     *
     * Нийлүүлэгдсэн бол БОДИТ хэмжээ, үгүй бол захиалсан. Машин 7 м³-аар
     * ирдэг тул 18 захиалбал 21 ирнэ — төсвийг 21-ээр нь иднэ, 18-аар
     * биш. Эс тэгвээс үлдэгдэл аажмаар худал болно.
     */
    public function consumedQty(): float
    {
        if (! in_array($this->status, self::COMMITTED_STATES, true)) {
            return 0.0;
        }

        return $this->delivered_qty ?? $this->quantity;
    }

    /**
     * Хугацаа нь хэтэрсэн яаралтай захиалга эсэх.
     *
     * Хориглохгүй — бодит амьдралд яаралтай цутгалт гардаг. Харин
     * ТЭМДЭГЛЭНЭ: үйлдвэр 3 хоногийн өмнө мэдэх ёстой гэж бланк дээр
     * бичсэн тул хожуу захиалга нь эрсдэл бөгөөд хэн нэгэн мэдэх ёстой.
     */
    public function isRush(): bool
    {
        return $this->pour_date !== null
            && $this->pour_date->lt(now()->startOfDay()->addDays(self::LEAD_DAYS));
    }
}
