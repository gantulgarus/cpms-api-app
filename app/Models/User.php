<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Хэрэглэгч ба түүний хамрах хүрээ.
 *
 * `scope_block_ids` хоосон бол бүх блок харагдана (удирдлагын түвшин).
 * Утга байвал зөвхөн тэдгээр блок — талбайн инженер 1–2 блок хариуцна.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    /**
     * Системд байж болох бүх үүрэг ба монгол нэр.
     *
     * Энэ жагсаалт нь дэлгэцийн сонголтын эх сурвалж — үүргийг хоёр газар
     * бичвэл (backend-д код, frontend-д шошго) хэзээ нэгэн цагт зөрнө.
     * Тиймээс `/users/roles` эндээс уншиж буцаана.
     */
    public const ROLES = [
        'admin' => 'Системийн админ',
        'director' => 'Захирал',
        'general_engineer' => 'Ерөнхий инженер',
        'project_manager' => 'Төслийн менежер',
        'inspector' => 'Хяналтын инженер',
        'site_engineer' => 'Талбайн инженер',
        'machine_operator' => 'Машин механизмын оператор',
        'contractor' => 'Туслан гүйцэтгэгч',
    ];

    /**
     * ЗӨВХӨН ХАРАХ үүргүүд.
     *
     * Захирал нь юу ч БАТЛАХГҮЙ: шалгахгүй, батлахгүй, төсөв оруулахгүй,
     * хэрэглэгч бүртгэхгүй. Түүний ажил нь явц, зардлыг ХАРАХ.
     *
     * ЯАГААД ЧУХАЛ ВЭ: удирдлагын эрхийг «бүх зүйлд хүрнэ» гэж ойлгох нь
     * хяналтыг утгагүй болгодог. Захирал ажлыг батлах боломжтой бол
     * «мэдээлэгч ба батлагч өөр хүн» гэсэн дүрмийг тойрох зам нээгдэнэ:
     * хэн ч захирлаар батлуулж болно. Мөн 1,991 мөрийг захирал хардаггүй —
     * батлах эрх нь зүгээр л ашиглагдахгүй эрсдэл болж үлддэг.
     *
     * МӨНГӨ ХАРАХ нь үлдэнэ: тохиролцоо, акт, төсвийн зөрүүг захирал
     * мэдэх ёстой. Харах ба батлах хоёр өөр зүйл.
     */
    public const OBSERVER_ROLES = ['director'];

    /**
     * Хэрэглэгч бүртгэх, үүрэг оноох эрх.
     *
     * ЗӨВХӨН АДМИН. Захирал хасагдсан: үүрэг оноох боломжтой хүн өөртөө
     * ямар ч эрх өгч чадна — тэр нь «юу ч батлахгүй» гэсэн дүрмийг
     * нэг товчоор тойрох зам.
     */
    public const USER_MANAGER_ROLES = ['admin'];

    /** Бүх блокийг харах эрхтэй үүргүүд. */
    public const UNRESTRICTED_ROLES = ['admin', 'director', 'general_engineer'];

    /**
     * Гүйцэтгэл мэдээлэх эрхтэй үүргүүд.
     *
     * Захиалагч: "Өдөр тутмын гүйцэтгэлийг талбайн инженер оруулна", мөн
     * "туслан гүйцэтгэгч өөрөө ажлаа оруулдаг байх нь зүйтэй" — учир нь
     * инженерүүд мэдээллийг удаашруулж, гүйцэтгэгчийн үнэлгээ гутаадаг.
     */
    public const REPORTER_ROLES = ['site_engineer', 'contractor', 'project_manager', 'machine_operator'];

    /**
     * Гүйцэтгэлийг баталгаажуулах эрхтэй үүргүүд.
     *
     * Мэдээлэх ба батлах эрхийг ЗААВАЛ салгана. v1-д баталгаажуулалт нь
     * тайлбар доторх `[БАТЛАВ]` текст байсан тул гүйцэтгэгч өөрийгөө баталж
     * чаддаг байв — захиалагчийн гомдлын гол шалтгаан.
     */
    public const INSPECTOR_ROLES = ['inspector', 'general_engineer', 'admin'];

    public function canReportProgress(): bool
    {
        return in_array($this->role, self::REPORTER_ROLES, true);
    }

    public function canInspect(): bool
    {
        return in_array($this->role, self::INSPECTOR_ROLES, true);
    }

    /**
     * 1-Р ШАТЫГ (талбайн шалгалт) хийх эрх.
     *
     * `canInspect()`-ЭЭС ӨРГӨН: талбайн инженер 1-р шатыг хийдэг ч
     * эцсийн батлах эрхгүй. Хоёрыг нэг эрхээр хэмжвэл талбайн инженер
     * шалгах форм огт харахгүй болж, хоёр шаттай урсгал гацна — яг
     * тэр алдаа дэлгэц дээр гарсан.
     *
     * Эх сурвалж нь `Inspection::STAGE_ROLES` — хоёр газар жагсаалт
     * бичихгүй.
     */
    public function canCheck(): bool
    {
        return in_array($this->role, Inspection::STAGE_ROLES[Inspection::STAGE_SITE], true);
    }

    /**
     * Ажилд хяналтын инженер оноох эрх.
     *
     * Гүйцэтгэгч оноохоос (`canManageContractors`) САЛГАВ: гүйцэтгэгч оноох
     * нь гэрээний шийдвэр, хяналтын инженер оноох нь дотоод хуваарилалт.
     * Ерөнхий инженер хоёр дахийг шийдэх ёстой ч гэрээнд хүрэх эрхгүй.
     */
    public function canAssignInspectors(): bool
    {
        return in_array($this->role, ['admin', 'general_engineer', 'project_manager'], true);
    }

    /** Гүйцэтгэгч бүртгэх, нэвтрэх код олгох эрх. */
    public function canManageContractors(): bool
    {
        return in_array($this->role, ['admin', 'project_manager'], true);
    }

    /**
     * Зөвхөн харах үүрэг эсэх.
     *
     * Бичих эрхийн шалгалт бүр үүнийг ДАГАНА — шинэ дэлгэц нэмэхэд
     * захирлыг тусад нь хасах шаардлагагүй болно.
     */
    public function isObserver(): bool
    {
        return in_array($this->role, self::OBSERVER_ROLES, true);
    }

    /**
     * МӨНГӨНИЙ мэдээлэл харах эрх — тохиролцоо, акт, төсвийн дүн.
     *
     * Батлахаас ТУСДАА: захирал батлахгүй ч зардлаа мэдэх ёстой.
     */
    public function canSeeMoney(): bool
    {
        return in_array(
            $this->role,
            ['admin', 'director', 'general_engineer', 'project_manager'],
            true,
        );
    }

    /** Хэрэглэгч бүртгэх, үүрэг оноох эрх. */
    public function canManageUsers(): bool
    {
        return in_array($this->role, self::USER_MANAGER_ROLES, true);
    }

    /**
     * Төслийн төлөвлөгөө — тоо хэмжээ, хугацаа засах эрх.
     *
     * Тоо хэмжээ бол төслийн ХАМРАХ ХҮРЭЭ: талбайн инженер өөрөө өөрчилвөл
     * гүйцэтгэлийн хувь утгагүй болно ("100% болгохын тулд төлөвлөгөөг
     * буулгав" гэсэн эрсдэл). Тиймээс удирдлагын түвшинд үлдээв.
     */
    public function canEditPlan(): bool
    {
        return in_array($this->role, ['admin', 'general_engineer', 'project_manager'], true);
    }

    /**
     * Лавлах сан (ажлын төрөл, бүлэг, загвар) засварлах эрх.
     *
     * Ажлын төрөл өөрчлөх нь бүх барилгын тооцоонд нөлөөлнө — ерөнхий
     * инженерээс дээш түвшний шийдвэр.
     */
    public function canManageReferenceData(): bool
    {
        return in_array($this->role, ['admin', 'general_engineer'], true);
    }

    protected $fillable = [
        'name', 'email', 'password',
        'role', 'position', 'unit',
        'scope_block_ids', 'contractor_id', 'is_active', 'is_demo',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'scope_block_ids' => 'array',
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
        ];
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * Туслан гүйцэтгэгчийн төлөөлөгч эсэх.
     *
     * Тэдний хамрах хүрээ нь блок БИШ, гүйцэтгэгч. Тиймээс доорх блокийн
     * дүрмүүд тэдэнд хамаарахгүй — `WorkScope` шийднэ.
     */
    public function isContractorRep(): bool
    {
        return $this->role === 'contractor' && $this->contractor_id !== null;
    }

    public function canSeeAllBlocks(): bool
    {
        // ЧУХАЛ: гүйцэтгэгчийн `scope_block_ids` хоосон байдаг. Доорх
        // `empty()`-г нь тэр чигээр хэрэглэвэл "хоосон = хязгаарлалтгүй" гэж
        // уншигдаж, гүйцэтгэгч БҮХ барилгын БҮХ ажлыг хардаг болно.
        if ($this->isContractorRep()) {
            return false;
        }

        return in_array($this->role, self::UNRESTRICTED_ROLES, true)
            || empty($this->scope_block_ids);
    }

    public function canSeeBlock(string $blockId): bool
    {
        // Гүйцэтгэгч ажил хийж байгаа барилгаа хардаг — өөр барилгыг хардаггүй.
        if ($this->isContractorRep()) {
            return WorkItem::query()
                ->where('block_id', $blockId)
                ->where('contractor_id', $this->contractor_id)
                ->exists();
        }

        return $this->canSeeAllBlocks() || in_array($blockId, $this->scope_block_ids ?? [], true);
    }
}
