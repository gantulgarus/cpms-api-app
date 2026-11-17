# CPMS API v2 — Laravel

Инэл ХХК-ийн барилгын удирдлагын системийн backend. `cpms-web/docs/api-v2-endpoints.md`-д
тодорхойлсон гэрээг хэрэгжүүлнэ. Одоогийн v1 (Node, `:4000`) зэрэгцэн ажиллана.

**Стек:** Laravel 12 · PostgreSQL 16 · Sanctum token

## Суулгах

Энэ хавтас нь **бүтэн Laravel төсөл биш** — зөвхөн домэйний файлууд. Laravel-ийн
суурь бүтцийг өөрөө үүсгээд дээр нь хуулна. Ингэснээр `composer.json`, `config/*`
зэрэг өөрчлөгддөг boilerplate-ыг гар аргаар зохиохгүй.

```bash
# 1. Цэвэр Laravel-ийг ЭНЭ хавтас дотор үүсгэх
cd cpms-api
composer create-project laravel/laravel cpms-api-app
cd cpms-api-app
# routes/api.php ба Sanctum-ыг нэг дор нэмнэ (Laravel 11+ дээр заавал)
php artisan install:api

# 2. Домэйний файлуудыг дээр нь нэгтгэх.
#    `/.` нь заавал — эс бөгөөс ./app/app гэсэн үүрлэсэн хавтас үүснэ.
for d in app bootstrap database routes tests; do cp -R "../$d/." "./$d/"; done

# 3. PostgreSQL холболт (.env)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=cpms
DB_USERNAME=cpms
DB_PASSWORD=secret

# 4. Хүснэгт үүсгэж, ажлын төрлийн сан ачаалах
# `--seed` нь DatabaseSeeder → DemoSeeder дуудна: хэрэглэгч, компани, төсөл,
# 47 ажлын төрөл, бүтэн блок (3,290 ажлын нэгж) бүгд үүснэ.
php artisan migrate:fresh --seed

# Тест PostgreSQL-ийн тусдаа санг ашиглана (.env.testing)
createdb cpms_test

# 5. Зургийн сан бэлдэх (private disk, гарын үсэгтэй хаягаар үйлчилнэ)
php artisan storage:link

# 6. Ажиллуулах
php artisan serve --port=8000
php artisan queue:work        # загвар буулгах job-д шаардлагатай
```

Frontend-ийг холбох:

```bash
# cpms-web дотор
CPMS_API_URL=http://127.0.0.1:8000/api/v1 NEXT_PUBLIC_CPMS_V2=1 npm run dev
```

`CPMS_MOCK` тавихгүй — тэгвэл proxy жинхэнэ Laravel рүү явна.

## Production шинэчлэх

Backend болон frontend нь тусдаа Git төсөл. Backend-ийн `artisan` файлтай
хавтсанд кодоо шинэчлээд өгөгдлийн сангийн шинэчлэлтийг ажиллуулна.

```bash
git pull
php artisan optimize:clear
php artisan migrate --force
php artisan cpms:import-work-catalog
php artisan cpms:resolve-catalog-locations
php artisan cpms:sync-catalog-designs
php artisan queue:restart
```

Импорт нь 2026-11-16-ны шинэ лавлахыг ачаална. Давтан ажиллуулахад идэвхтэй
ажил давхардахгүй, устгасан лавлахын мөр сэргээгдэхгүй. Өмнө үүссэн барилгын
ажлууд хуучин `work_type_id` холбоосоо хадгалдаг. Git-ийн өөрчлөлт дангаараа
серверийн өгөгдлийн сан дахь барилга, ажлыг солихгүй.

### Бүх барилга тестийн өгөгдөлтэй орчныг шинэ лавлахад шилжүүлэх

Доорх хувилбарыг бүх барилга нь тестийнх гэдгийг баталгаажуулсан орчинд
нэг удаа ашиглана. Команд барилга, тэдгээрийн ажил, гүйцэтгэл, төсөв,
захиалга, зураг болон хуучин лавлахын холбоосыг цэвэрлэнэ. Хэрэглэгч,
компани, төсөл, шинэ лавлахын бүртгэл болон барилгын загварууд хадгалагдана.

```bash
git pull
php artisan down
php artisan optimize:clear
php artisan migrate --force
php artisan cpms:refresh-test-reference
php artisan cpms:refresh-test-reference --execute
php artisan queue:restart
php artisan up
```

Эхний `refresh-test-reference` зөвхөн тооллого харуулна. `--execute` нь
цэвэрлэгээ, импорт, байршил тодорхойлох, загвар холбохыг нэг transaction-д
гүйцэтгэнэ. Алдаа гарвал өгөгдлийн өөрчлөлт буцна. Команд алдаатай дуусвал
алдааг зассаны дараа дахин ажиллуулна. Queue worker-ийг Supervisor/systemd
зэрэг процесс удирдагчаар ажиллуулсан байх шаардлагатай.

Frontend код өөрчлөгдсөн бол frontend төсөлд build хийж өөрийн ашиглаж буй
процесс удирдагчаар веб процессыг дахин ачаална.

`DemoSeeder` нь хуучин танилцуулгын барилга, ажлуудыг үүсгэдэг тестийн
өгөгдөл. Шинэ лавлах байрлуулах deploy алхамд энэ seeder-ийг дуудахгүй.
`migrate:fresh` нь бүх хүснэгтийг устгадаг учраас дээрх шинэчлэлтийн урсгалд
хэрэглэхгүй.

### Жинхэнэ төсөл эхлэхэд demo өгөгдлийг устгах

```bash
php artisan demo:purge          # юу устахыг харуулж, баталгаажуулалт асууна
php artisan demo:purge --force  # асуулгүй
```

`demo:purge` нь ЗӨВХӨН `is_demo` тугтай мөрийг устгана. Нэрээр, кодоор хайдаггүй
нь санаатай: demo компани «Инэл ХХК» гэж нэрлэгдсэн бөгөөд тэр нь жинхэнэ
захиалагчийн нэр тул нэрээр хайвал жинхэнэ өгөгдөл устах эрсдэлтэй.

| Устана | Үлдэнэ |
|---|---|
| Demo төсөл → блок, байршил, ажлын нэгж, явц, шалгалт, саатал, зураг (cascade) | Ажлын төрөл, бүлэг (47/15) |
| Demo хэрэглэгч (4), demo гүйцэтгэгч (6) | Чанарын шалгах хуудас (5) |
| Зургийн файлууд дискнээс | Зураг төслийн загвар (3) |
| | Компанийн бүртгэл |

**Анхаар:** тугийг `DemoSeeder` тавьдаг. Энэ өөрчлөлтөөс ӨМНӨ гараар үүсгэсэн
өгөгдөл туггүй тул `demo:purge` түүнд хүрэхгүй — шаардвал гараар устгана.

### Жишээ зургийг жинхэнээр солих

`database/seeders/demo-photos/` доторх файлыг сольж, `demo:purge` хийгээд дахин
seed хийнэ. Seeder нь хавтсыг уншдаг тул `.jpg`, `.png`, `.webp` аль нь ч болно.

## Загварын гол зарчим

**Ажлын төрөл × байршил = ажлын нэгж.** `work_types` (юу хийх) ба `locations`
(хаана хийх) тусдаа. Тэдний огтлолцол нь `work_items`. Ажлын төрөл бүр өөрт
тохирох түвшинд суудаг (`level`): дээвэр блокт нэг, угсралт давхар бүрт, паркет
айл бүрт. Нэг 16 давхрын блокт ~3,290 мөр гарна.

**Тоо хэмжээ нь эх сурвалж.** `planned_qty` → `reported_qty` (гүйцэтгэгч мэдээлсэн)
→ `accepted_qty` (хяналтын инженер баталсан). Үлдэгдэл ба хувь нь **зөвхөн
батлагдсанаас** бодогдоно.

**Хуримтлагдсан дүн `work_items`-д хадгалагдана.** `reported_qty`/`accepted_qty`-г
жагсаалт бүрд `progress_entries`-ээс дахин бодох нь N+1 болно. Тиймээс явц эсвэл
шалгалт нэмэгдэх бүрд transaction дотор шинэчилнэ (`WorkItem::recalculate`).

**Алдааны дугтуй нэг маягтай.** `bootstrap/app.php` доторх exception handler
бүх API алдааг `{ error: { name, message, details } }` болгож хувиргана.
Laravel-ийн анхдагч хэлбэр frontend-д ойлгогдохгүй тул хэрэглэгчид
"Request failed (422)" гэсэн утгагүй мессеж харагддаг байв.

**Байршил нь materialized path.** `path_key` = `/uuid/uuid/uuid`. Удмыг олоход
рекурс хэрэггүй, `where path_key like '/root/floor/%'` гэсэн ганц индекстэй
query болно.

## Юу орсон, юу дутуу

| Орсон | Дутуу (дараагийн үе шат) |
|---|---|
| Блок, байршил, ажлын төрөл, WorkItem | Материал, орц норм, агуулах |
| Явц бүртгэх, хоёр шатны шалгалт, rework | Тохирооны хуудас, үнэ, акт |
| Сервер талын нэгтгэл (`summary`, `dashboard`) | Мэдэгдэл, push |
| Загвар буулгах queued job | Excel импорт (одоогоор seeder) |
| Зургийн баримт — хавсаргах, харах, түгжих | Зургийг сервер талд багасгах |
| Sanctum + гүйцэтгэгчийн кодоор нэвтрэх | Бүрэн эрхийн matrix, audit log |

## Тест

```bash
php artisan test
```

`cpms-web/scripts/verify-mock.ts` доторх 46 шалгалтын гол хэсгийг PHPUnit болгож
хөрвүүлсэн — хуудаслалт, удмаар шүүх, нэгтгэлийн нийлбэр, тоо хэмжээний валидаци,
блокууд хоорондоо холилдохгүй байх.
