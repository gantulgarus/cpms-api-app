<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\BlockDesignController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\ContractorController;
use App\Http\Controllers\Api\DashboardController;
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
| CPMS API v1 (загвар v2)
|--------------------------------------------------------------------------
| Зам нь `cpms-web/docs/api-v2-endpoints.md`-тэй яг таарна. Frontend нь
| `/api/v1/*` рүү хандана — proxy дамжуулна.
*/

Route::prefix('v1')->group(function () {
    /*
     * Зургийн файл — гарын үсэгтэй хаягаар. `<img src>` нь Authorization
     * толгой дамжуулж чаддаггүй тул token ажиллахгүй; гарын үсэг нь хандалтыг
     * хамгаалж, хугацаа нь дуусахад хаяг хүчингүй болно.
     */
    Route::get('photos/{photo}/file', [PhotoController::class, 'file'])
        ->middleware('signed')
        ->name('photos.file');

    // --- Нэвтрэлт ---
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/contractor-login', [AuthController::class, 'contractorLogin']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // --- Хэрэглэгч (зөвхөн админ, захирал) ---
        Route::get('users/roles', [UserController::class, 'roles']);
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
        Route::post('contractors/{contractor}/access-code', [ContractorController::class, 'issueAccessCode']);
        Route::delete('contractors/{contractor}/access-code', [ContractorController::class, 'revokeAccessCode']);

        // --- Төсөл ---
        Route::get('projects', [ProjectController::class, 'index']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::get('projects/{project}/blocks', [BlockController::class, 'index']);
        Route::post('projects/{project}/blocks', [BlockController::class, 'store']);
        Route::get('projects/{project}/dashboard', [DashboardController::class, 'show']);

        // --- "Надаас юу хүлээж байна" дараалал ---
        Route::get('projects/{project}/queue', [QueueController::class, 'index']);
        Route::get('projects/{project}/queue/counts', [QueueController::class, 'counts']);

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

        // --- Ажлын нэгж --- (мөн хамрах хүрээгээр хамгаалагдана)
        Route::middleware(EnsureWorkScope::class)->group(function () {
            Route::get('work-items/{workItem}', [WorkItemController::class, 'show']);
            Route::patch('work-items/{workItem}', [WorkItemPlanController::class, 'update']);
            Route::patch('work-items/{workItem}/contractor', [WorkItemAssignmentController::class, 'assignOne']);
            Route::delete('work-items/{workItem}', [WorkItemPlanController::class, 'destroy']);
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
