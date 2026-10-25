<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contractor extends Model
{
    use HasUuids;

    /**
     * Ажлын ангилал — лавлах.
     *
     * `trade_specialty` нь чөлөөт текст (хайлтад сайн), ангилал нь шүүх,
     * эрэмбэлэхэд. Хоёулаа хэрэгтэй: «Гипсэн хана, тааз, MDF, нерж» гэдэг
     * нь ангилал биш, тайлбар.
     */
    public const CATEGORIES = [
        'structure' => 'Угсралт, бетон',
        'masonry' => 'Өрлөг, шавардлага',
        'finishing' => 'Дотор засал',
        'facade' => 'Фасад, цонх',
        'mep' => 'Цахилгаан, сантехник, ХАС',
        'stone_floor' => 'Чулуу, шал',
        'doors' => 'Хаалга, төмөр хийц',
        'exterior' => 'Гадна ажил, тохижилт',
        'survey' => 'Геодези, хэмжилт',
        'other' => 'Бусад',
    ];

    /**
     * Төлөв.
     *
     * Устгахгүй, хаана: гэрээ цуцлагдсан гүйцэтгэгчийн акт, барьцаа, торгууль
     * нь түүхэнд үлдэх ёстой. Зөвхөн ШИНЭ ажил оноогдохыг хориглоно.
     */
    public const STATUSES = [
        'active' => 'Ажиллаж байгаа',
        'suspended' => 'Түр зогссон',
        'terminated' => 'Гэрээ цуцлагдсан',
    ];

    protected $fillable = [
        'name', 'company_name', 'registration_no', 'trade_specialty', 'category',
        'status', 'contact_person', 'phone', 'email', 'bank_name', 'bank_account',
        'access_code', 'access_code_expires_at', 'is_demo',
    ];

    protected $hidden = ['access_code'];

    protected function casts(): array
    {
        return ['access_code_expires_at' => 'datetime', 'is_demo' => 'boolean'];
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /**
     * Шинэ ажил оноож болох эсэх.
     *
     * Гэрээ цуцлагдсан гүйцэтгэгчид ажил оноовол акт нь түүн рүү явж,
     * төлбөр гарах эрсдэлтэй.
     */
    public function canTakeWork(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Тохиролцоо бичихэд БЭЛЭН эсэх.
     *
     * «Ажил гүйцэтгэх тохиролцооны хуудас» нь РД, данс, банкийг шаарддаг.
     * Эдгээргүйгээр хуудас хэвлэгдэхгүй, төлбөр гарахгүй.
     *
     * @return list<string>  дутуу талбаруудын нэр
     */
    public function missingContractFields(): array
    {
        $missing = [];

        if (! $this->registration_no) {
            $missing[] = 'Регистрийн дугаар';
        }
        if (! $this->bank_account) {
            $missing[] = 'Дансны дугаар';
        }
        if (! $this->bank_name) {
            $missing[] = 'Банк';
        }
        if (! $this->phone) {
            $missing[] = 'Холбоо барих утас';
        }

        return $missing;
    }

    /** Ажил гүйцэтгэх хугацаанд олгогдсон код хүчинтэй эсэх. */
    public function hasValidAccessCode(): bool
    {
        return $this->access_code
            && (! $this->access_code_expires_at || $this->access_code_expires_at->isFuture());
    }
}
