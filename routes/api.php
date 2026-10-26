<?php

use App\Http\Controllers\Api\AgreementController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\BlockDesignController;
use App\Http\Controllers\Api\BudgetImportController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\ConcreteOrderController;
use App\Http\Controllers\Api\ContractorController;
use App\Http\Controllers\Api\MaterialBudgetController;
use App\Http\Controllers\Api\MaterialOrderController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExtensionRequestController;
use App\Http\Controllers\Api\IssueController;
use App\Http\Controllers\Api\QueueController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WorkItemAssignmentController;
use App\Http\Controllers\Api\WorkItemController;
use App\Http\Controllers\Api\WorkItemPlanController;
use App\Http\Controllers\Api\WorkTypeController;
use App\Http\Middleware\EnsureWorkScope;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CPMS API
|--------------------------------------------------------------------------
| Зам нь `cpms-web/docs/api-endpoints.md`-тэй яг таарна. Frontend нь
| `/api/v1/*` рүү хандана — proxy дамжуулна. `v1` нь зөвхөн URL-ийн
| угтвар (гар утасны апп ч үүгээр ханддаг), өөр хувилбар байхгүй.
*/

Route::prefix('v1')->group(function () {
    /*
     * Зургийн файл — гарын үсэгтэй хаягаар. `<img src>` нь Authorization
     * толгой дамжуулж чаддаггүй тул token ажиллахгүй; гарын үсэг нь хандалтыг
     * хамгаалж, хугацаа нь дуусахад хаяг хүчингүй болно.
     */
    Route::get('photos/{photo}/file', [PhotoController::class, 'file'])
        // `signed:relative` — гарын үсэг нь зөвхөн зам + query дээр тооцогдоно.
        // Хост шалгахгүй тул прокси дамжуулсан ч хүчинтэй хэвээр.
        ->middleware('signed:relative')
        ->name('photos.file');

    // --- Нэвтрэлт ---
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/contractor-login', [AuthController::class, 'contractorLogin']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // --- Хэрэглэгч (зөвхөн админ, захирал) ---
        Route::get('users/roles', [UserController::class, 'roles']);
        // Хяналтын инженер сонгох жагсаалт — оноох эрхтэй бүх үүрэгт нээлттэй.
        Route::get('users/inspectors', [UserController::class, 'inspectors']);
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::get('users/{user}', [UserController::class, 'show']);
        Route::patch('users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::delete('users/{user}', [UserController::class, 'deactivate']);

        // --- Гүйцэтгэгч ба тэдний нэвтрэх эрх ---
        Route::get('contractors', [ContractorController::class, 'index']);
        Route::post('contractors', [ContractorController::class, 'store']);
        Route::get('contractors/{contractor}', [ContractorController::class, 'show']);
        Route::patch('contractors/{contractor}', [ContractorController::class, 'update']);
        Route::post('contractors/{contractor}/access-code', [ContractorController::class, 'issueAccessCode']);
        Route::delete('contractors/{contractor}/access-code', [ContractorController::class, 'revokeAccessCode']);

        // --- Төсөл ---
        Route::get('companies', [ProjectController::class, 'companies']);
        Route::get('projects', [ProjectController::class, 'index']);
        Route::post('projects', [ProjectController::class, 'store']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::get('projects/{project}/blocks', [BlockController::class, 'index']);
        Route::post('projects/{project}/blocks', [BlockController::class, 'store']);
        Route::get('projects/{project}/dashboard', [DashboardController::class, 'show']);

        // --- "Надаас юу хүлээж байна" дараалал ---
        Route::get('projects/{project}/queue', [QueueController::class, 'index']);
        Route::get('projects/{project}/queue/counts', [QueueController::class, 'counts']);

        Route::get('projects/{project}/my-work', [\App\Http\Controllers\Api\MyWorkController::class, 'index']);

        // --- Тайлан ---
        Route::get('projects/{project}/reports/acceptance', [ReportController::class, 'acceptance']);
        Route::get('projects/{project}/reports/acceptance.xlsx', [ReportController::class, 'acceptanceXlsx']);

        // --- Асуудал ---
        Route::get('projects/{project}/issues', [IssueController::class, 'index']);
        Route::patch('issues/{issue}', [IssueController::class, 'update']);

        // --- Блок --- (хамрах хүрээг серверт шалгана)
        Route::middleware(EnsureWorkScope::class)->group(function () {
            Route::get('blocks/{block}', [BlockController::class, 'show']);
            Route::get('blocks/{block}/locations', [BlockController::class, 'locations']);

            /*
             * Блок УСТГАХ — зөвхөн админ, нэр бичиж баталгаажуулна.
             *
             * Байршил, ажлын мөр, явц, шалгалт, зураг, төсөв, захиалга
             * бүгд каскадаар дагаж устана. Урьдчилан харах дуудлага нь
             * юу алга болохыг тоогоор хэлнэ.
             */
            /*
             * Блокийн ЕРӨНХИЙ тайлан — гүйцэтгэл, мөнгө, хугацаа,
             * чанар, материал тав нэг хуудсанд.
             */
            /** Блокт ОРСОН төсөв — ажлын хөлс ба материал. */
            Route::get('blocks/{block}/budget', [BlockController::class, 'budget']);
            Route::patch('blocks/{block}/budget/work-price', [BlockController::class, 'setWorkPrice']);

            Route::get('blocks/{block}/report', [BlockController::class, 'report']);
            Route::get('blocks/{block}/report.xlsx', [BlockController::class, 'reportSheet']);

            Route::get('blocks/{block}/deletion-impact', [BlockController::class, 'deletionImpact']);
            Route::delete('blocks/{block}', [BlockController::class, 'destroy']);
            Route::get('blocks/{block}/work-items', [WorkItemController::class, 'index']);
            Route::get('blocks/{block}/summary', [WorkItemController::class, 'summary']);

            // --- Төлөвлөгөөт тоо хэмжээ гүйцээх ---
            Route::get('blocks/{block}/missing-quantities', [WorkItemPlanController::class, 'missingQuantities']);
            Route::post('blocks/{block}/work-items/set-quantity', [WorkItemPlanController::class, 'setQuantity']);

            // --- Хариуцагч оноох ---
            Route::get('blocks/{block}/assignments', [WorkItemAssignmentController::class, 'summary']);
            Route::post('blocks/{block}/work-items/assign', [WorkItemAssignmentController::class, 'assign']);

            // --- Ажлыг гараар нэмэх (загваргүй блокт) ---
            Route::post('blocks/{block}/work-items', [WorkItemPlanController::class, 'addWorkType']);

            // --- Хуваарь дахин татах (давхрын хугацаа) ---
            Route::post('blocks/{block}/schedule', [WorkItemPlanController::class, 'schedule']);
        });

        // --- Лавлах сан: чанарын шалгах хуудас ---
        Route::get('checklist-templates', [ChecklistController::class, 'index']);
        Route::post('checklist-templates', [ChecklistController::class, 'store']);
        Route::patch('checklist-templates/{checklistTemplate}', [ChecklistController::class, 'update']);
        Route::delete('checklist-templates/{checklistTemplate}', [ChecklistController::class, 'destroy']);
        Route::post('checklist-templates/{checklistTemplate}/items', [ChecklistController::class, 'storeItem']);
        Route::delete('checklist-items/{checklistItem}', [ChecklistController::class, 'destroyItem']);

        // --- Лавлах сан: ажлын төрөл ба бүлэг ---
        Route::get('work-type-groups', [WorkTypeController::class, 'groups']);
        Route::post('work-type-groups', [WorkTypeController::class, 'storeGroup']);
        Route::patch('work-type-groups/{workTypeGroup}', [WorkTypeController::class, 'updateGroup']);
        Route::delete('work-type-groups/{workTypeGroup}', [WorkTypeController::class, 'destroyGroup']);

        Route::get('work-types', [WorkTypeController::class, 'index']);
        Route::post('work-types', [WorkTypeController::class, 'store']);
        Route::get('work-types/{workType}', [WorkTypeController::class, 'show']);
        Route::patch('work-types/{workType}', [WorkTypeController::class, 'update']);
        Route::delete('work-types/{workType}', [WorkTypeController::class, 'destroy']);

        // --- Загвар ---
        Route::get('block-designs', [BlockDesignController::class, 'index']);
        Route::get('block-designs/{blockDesign}', [BlockDesignController::class, 'show']);
        Route::post('block-designs/{blockDesign}/apply', [BlockController::class, 'applyDesign']);
        Route::get('jobs/{batchId}', [BlockController::class, 'job']);

        /*
         * --- Ажил гүйцэтгэх тохиролцооны хуудас ---
         *
         * МӨНГӨ системд эндээс орно: акт нь тохиролцооны нэгж үнэ дээр
         * тогтоно. Бичих нь гэрээний шийдвэр (`canManageContractors`),
         * батлах нь ерөнхий инженерийн.
         */
        Route::get('projects/{project}/agreements', [AgreementController::class, 'index']);
        Route::get('blocks/{block}/agreement-draft', [AgreementController::class, 'draft']);
        Route::post('blocks/{block}/agreements', [AgreementController::class, 'store']);
        Route::get('agreements/{agreement}', [AgreementController::class, 'show']);
        Route::patch('agreements/{agreement}', [AgreementController::class, 'update']);
        Route::get('agreements/{agreement}/sheet.xlsx', [AgreementController::class, 'sheet']);
        Route::post('agreements/{agreement}/approve', [AgreementController::class, 'approve']);

        /*
         * --- Төсвийн импорт ---
         *
         * Хоёр алхам: байршуулж УРЬДЧИЛАН ХАРНА → тэр ЯГ ТЭР файлыг
         * батална. Файл id-гаар дуудагддаг тул хоёр дахь алхамд өөр
         * файл орохгүй.
         *
         * `EnsureWorkScope` хамаарахгүй: эрхийг хянагч өөрөө шалгана
         * (`canManageReferenceData`) — импорт нь блокийн бүх мөрийг
         * солидог тул хамрах хүрээнээс дээш түвшний шийдвэр.
         */
        // Загвар нь блокийн давхруудын нэрээр үүсдэг тул блокт харьяална.
        Route::get('blocks/{block}/budget-template.xlsx', [BudgetImportController::class, 'template']);
        Route::get('blocks/{block}/imports', [BudgetImportController::class, 'index']);
        Route::post('blocks/{block}/imports', [BudgetImportController::class, 'store']);
        Route::get('imports/{budgetImport}', [BudgetImportController::class, 'show']);
        Route::post('imports/{budgetImport}/apply', [BudgetImportController::class, 'apply']);
        Route::delete('imports/{budgetImport}', [BudgetImportController::class, 'destroy']);

        /*
         * --- Бетон зуурмагийн захиалга ---
         *
         * Үлдэгдлийн асуулга (`concrete-balance`) нь захиалга бичих
         * дэлгэцийн үндэс: байршил × марк бүрийн төсөв, захиалсан,
         * үлдэгдэл. Цаасан бланкаас ялгарах цорын гагц зүйл.
         */
        Route::get('projects/{project}/concrete-orders', [ConcreteOrderController::class, 'index']);
        Route::get('blocks/{block}/concrete-balance', [ConcreteOrderController::class, 'balance']);
        Route::post('blocks/{block}/concrete-orders', [ConcreteOrderController::class, 'store']);
        Route::get('concrete-orders/{concreteOrder}', [ConcreteOrderController::class, 'show']);
        Route::patch('concrete-orders/{concreteOrder}', [ConcreteOrderController::class, 'update']);
        Route::get('concrete-orders/{concreteOrder}/sheet.xlsx', [ConcreteOrderController::class, 'sheet']);
        Route::post('concrete-orders/{concreteOrder}/submit', [ConcreteOrderController::class, 'submit']);
        Route::post('concrete-orders/{concreteOrder}/confirm', [ConcreteOrderController::class, 'confirm']);
        Route::post('concrete-orders/{concreteOrder}/deliver', [ConcreteOrderController::class, 'deliver']);
        Route::post('concrete-orders/{concreteOrder}/cancel', [ConcreteOrderController::class, 'cancel']);

        /*
         * --- Материалын захиалга ---
         *
         * Дөрвөн бланк (үндсэн, туслах, модон, арматур) НЭГ урсгалаар.
         * Багана болгоноороо ижил тул дөрвөн дэлгэц барих нь ижил
         * алдааг дөрвөн газар засах гэсэн үг.
         */
        Route::get('projects/{project}/material-orders', [MaterialOrderController::class, 'index']);
        Route::get('blocks/{block}/material-balance', [MaterialOrderController::class, 'balance']);
        Route::post('blocks/{block}/material-orders', [MaterialOrderController::class, 'store']);
        Route::get('material-orders/{materialOrder}', [MaterialOrderController::class, 'show']);
        Route::patch('material-orders/{materialOrder}', [MaterialOrderController::class, 'update']);
        Route::get('material-orders/{materialOrder}/sheet.xlsx', [MaterialOrderController::class, 'sheet']);
        Route::post('material-orders/{materialOrder}/submit', [MaterialOrderController::class, 'submit']);
        Route::post('material-orders/{materialOrder}/confirm', [MaterialOrderController::class, 'confirm']);
        Route::post('material-orders/{materialOrder}/deliver', [MaterialOrderController::class, 'deliver']);
        Route::post('material-orders/{materialOrder}/cancel', [MaterialOrderController::class, 'cancel']);

        /*
         * Материалын төсвийн мөрийн ҮНЭ — гараар засах.
         *
         * Тоо хэмжээ энд засагдахгүй: тэр нь байршлаар задарсан тул
         * гараар засвал нийлбэр ба задаргаа зөрнө.
         */
        Route::post(
            'blocks/{block}/material-budget-items',
            [MaterialBudgetController::class, 'store'],
        );
        Route::patch(
            'material-budget-items/{materialBudgetItem}/price',
            [MaterialBudgetController::class, 'setPrice'],
        );
        Route::delete(
            'material-budget-items/{materialBudgetItem}',
            [MaterialBudgetController::class, 'destroy'],
        );

        // --- Хугацаа сунгах хүсэлт ---
        // Шийдвэрийг зөвхөн ерөнхий инженер, захирал гаргана — хуваарийн
        // гулсалтыг батлах нь төслийн менежерээс ДЭЭШ шатлалын шийдвэр.
        Route::get('projects/{project}/extension-requests', [ExtensionRequestController::class, 'index']);
        Route::post('extension-requests/{extensionRequest}/approve', [ExtensionRequestController::class, 'approve']);
        Route::post('extension-requests/{extensionRequest}/reject', [ExtensionRequestController::class, 'reject']);

        // --- Ажлын нэгж --- (мөн хамрах хүрээгээр хамгаалагдана)
        Route::middleware(EnsureWorkScope::class)->group(function () {
            Route::get('work-items/{workItem}', [WorkItemController::class, 'show']);
            Route::patch('work-items/{workItem}', [WorkItemPlanController::class, 'update']);
            Route::patch('work-items/{workItem}/contractor', [WorkItemAssignmentController::class, 'assignOne']);
            Route::patch('work-items/{workItem}/inspector', [WorkItemAssignmentController::class, 'assignInspector']);
            Route::delete('work-items/{workItem}', [WorkItemPlanController::class, 'destroy']);

            // --- Хугацаа сунгах (шалтгаан заавал) ---
            Route::post('work-items/{workItem}/extend', [WorkItemPlanController::class, 'extend']);
            Route::get('work-items/{workItem}/extension-requests', [ExtensionRequestController::class, 'forWorkItem']);
            Route::get('work-items/{workItem}/progress', [WorkItemController::class, 'progress']);
            Route::post('work-items/{workItem}/progress', [WorkItemController::class, 'storeProgress']);
            Route::get('work-items/{workItem}/inspections', [WorkItemController::class, 'inspections']);
            Route::post('work-items/{workItem}/inspections', [WorkItemController::class, 'storeInspection']);

            // --- Зургийн баримт ---
            // Шалгалт хийхийн өмнө "юу бөглөх вэ" гэдгийг серверээс асууна.
            Route::get('work-items/{workItem}/checklist', [ChecklistController::class, 'forWorkItem']);

            Route::get('work-items/{workItem}/issues', [IssueController::class, 'forWorkItem']);
            Route::post('work-items/{workItem}/issues', [IssueController::class, 'store']);

            Route::get('work-items/{workItem}/photos', [PhotoController::class, 'index']);
            Route::post('work-items/{workItem}/photos', [PhotoController::class, 'store']);
        });

        Route::delete('photos/{photo}', [PhotoController::class, 'destroy']);

        Route::delete('progress/{progress}', [WorkItemController::class, 'destroyProgress']);
    });
});
