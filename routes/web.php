<?php

use Illuminate\Support\Facades\Route;
use App\Services\EwsMailReader;

// Controllers
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ConfigurationController;
use App\Http\Controllers\HoldReasonController;
use App\Http\Controllers\Auth\CustomAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ChangeRequest\Api\EmailApprovalController;
use App\Http\Controllers\ChangeRequest\ChangeRequestController;
use App\Http\Controllers\CustomFields\CustomFieldGroupTypeController;
use App\Http\Controllers\CustomFields\CustomFieldController as CustomFieldsController;
use App\Http\Controllers\CustomField\CustomFieldController as CustomFieldController;
use App\Http\Controllers\Users\UserController;
use App\Http\Controllers\Groups\GroupController;
use App\Http\Controllers\Statuses\StatusController;
use App\Http\Controllers\Division_manager\Division_managerController;
use App\Http\Controllers\Director\DirectorController;
use App\Http\Controllers\KpiType\KpiTypeController;
use App\Http\Controllers\KpiPillar\KpiPillarController;
use App\Http\Controllers\KpiInitiative\KpiInitiativeController;
use App\Http\Controllers\KpiSubInitiative\KpiSubInitiativeController;
use App\Http\Controllers\Units\UnitController;
use App\Http\Controllers\Stages\StageController;
use App\Http\Controllers\RequesterDepartment\RequesterDepartmentController;
use App\Http\Controllers\Parents\ParentController;
use App\Http\Controllers\highLevelStatuses\highLevelStatusesControlller;
use App\Http\Controllers\Search\SearchController;
use App\Http\Controllers\Workflow\NewWorkFlowController;
use App\Http\Controllers\Applications\ApplicationController;
use App\Http\Controllers\RejectionReasons\RejectionReasonsController;
use App\Http\Controllers\Roles\RolesController;
use App\Http\Controllers\Permissions\PermissionsController;
use App\Http\Controllers\Releases\CRSReleaseController;
use App\Http\Controllers\Releases\ReleaseController;
use App\Http\Controllers\CabUser\CabUserController;
use App\Http\Controllers\Defect\DefectController;
use App\Http\Controllers\Sla\SlaCalculationController;
use App\Http\Controllers\Prerequisites\PrerequisitesController;
use App\Http\Controllers\FinalConfirmation\FinalConfirmationController;
use App\Http\Controllers\NotificationTemplates\NotificationTemplatesController;
use App\Http\Controllers\KPIs\KPIController;
use App\Http\Controllers\KpiProject\KpiProjectController;
use App\Http\Controllers\Project\ProjectController;
use App\Http\Controllers\Report\ReportController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// --- Public / Auth Routes ---
Route::get('/mail_approve', [EmailApprovalController::class, 'ApproveMail']);
Route::get('login', [CustomAuthController::class, 'index'])->middleware('guest')->name('login');
Route::post('login', [CustomAuthController::class, 'login'])->name('login.custom')->middleware('throttle:5,1');
Route::get('/logout', [LoginController::class, 'logout']);
Route::get('/inactive-logout', [CustomAuthController::class, 'inactive_logout'])->name('inactive-logout');
Route::get('/check-active', [CustomAuthController::class, 'check_active'])->name('check-active');

Route::get('/cr/division_manager/action', [ChangeRequestController::class, 'handleDivisionManagerAction'])->name('cr.division_manager.action');

// --- Authenticated Routes ---
Route::middleware(['auth'])->group(function () {

    // --- Home & Dashboard ---
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/dashboard', [HomeController::class, 'StatisticsDashboard']);
    Route::get('/statistics', [HomeController::class, 'StatisticsDashboard']);
    Route::post('/charts_dashboard', [HomeController::class, 'dashboard']);
    Route::get('/application_based_on_workflow', [HomeController::class, 'application_based_on_workflow']);

    Route::get('/select/group', [HomeController::class, 'SelectGroup'])->name('select.group');
    Route::post('/select/group', [HomeController::class, 'storeGroup'])->name('store.group');

    // --- Change Request Management ---
    Route::controller(ChangeRequestController::class)->group(function () {
        Route::get('/change_request/approved_active', 'handleDivisionManagerAction1');
        Route::get('/change_request2/approved_active_cab', 'handlePendingCap');
        Route::get('/change_request2/approved_continue', 'approved_continue');
        Route::get('my_assignments', 'my_assignments');
        Route::post('change_request/listCRsUsers', 'Crsbyusers');
        Route::get('change_request/listcrsbyuser', 'list_crs_by_user');
        Route::get('change_request/export-user-created-crs', 'exportUserCreatedCRs')->name('change_request.export_user_created_crs');
        Route::get('change_request/on-hold', 'cr_hold_promo')->name('cr_hold');
        Route::get('change_request2/dvision_manager_cr', 'dvision_manager_cr')->name('dvision_manager_cr');
        Route::get('change_request2/cr_pending_cap', 'cr_pending_cap')->name('cr_pending_cap');
        Route::get('dvision_manager_cr/unreadNotifications', 'unreadNotifications');
        Route::get('change_request1/selectUserGroup/{group?}', 'selectUserGroup')->name('change_request.selectUserGroup');
        Route::post('/select-group/{group}', 'selectGroup')->name('select_group');
        Route::post('/change-requests/reorder', 'reorderChangeRequest')->name("change-requests.reorder");
        Route::post('/change-requests/hold', 'holdChangeRequest')->name("change-requests.hold");
        Route::get('/change-requests/reorder/home', 'reorderhome')->name("change-requests.reorder.home");
        Route::get('change_request/workflow/type', 'Allsubtype');
        Route::get('files/download/{id}', 'download')->name('files.download');
        Route::get('files/delete/{id}', 'deleteFile')->name('files.delete');
        Route::get('cr/{id}', 'show')->name('show.cr');
        Route::get('change_request/{id}/edit', 'edit')->name('edit.cr');
        Route::get('change_request/{id}/edit_cab', 'edit_cab')->name('edit_cab.cr');
        Route::get('testable_form', 'showTestableForm')->name('testable_form');
        Route::post('update_testable', 'updateTestableFlag')->name('update_testable');
        Route::get('top_management_crs', 'showTopManagementForm')->name('top_management_crs')->middleware('permission:Access Top Management CRS');
        Route::post('update_top_management', 'updateTopManagementFlag')->name('update_top_management')->middleware('permission:Update Top Management Flag');
        Route::post('top_management/export-table', 'exportTopManagementTable')->name('export.top_management.table')->middleware('permission:Access Top Management CRS');
        Route::get('add_attachments_form', 'showAddAttachmentsForm')->name('add_attachments_form');
        Route::post('store_attachments', 'storeAttachments')->name('store_attachments');
        Route::post('change_request/{change_request}/upload_dev_attachments', 'uploadDevAttachments')->name('change_request.upload_dev_attachments');
        Route::post('/change-requests/man-days/update', 'updateManDaysDate')->name('change-requests.man-days.update');
        Route::post('/change-requests/need-update', 'handleNeedUpdate')->name('change-requests.need-update');
    });

    Route::resource('change_request', ChangeRequestController::class);

    Route::get('top_management_crs/form', function () {
        return view('change_request.top_management_form');
    })->name('top_management_crs.form')->middleware('permission:Edit Top Management Form');

    // --- Top Management CRS CRUD Routes ---
    Route::prefix('top_management_crs')->name('top_management_crs.')->middleware('auth')->group(function () {
        Route::get('list', [ChangeRequestController::class, 'listTopManagementCrs'])->name('list')->middleware('permission:List Top Management CRS');
        Route::get('create', [ChangeRequestController::class, 'createTopManagementCr'])->name('create')->middleware('permission:Create Top Management CRS');
        Route::post('store', [ChangeRequestController::class, 'storeTopManagementCr'])->name('store')->middleware('permission:Create Top Management CRS');
        Route::get('{id}/edit', [ChangeRequestController::class, 'editTopManagementCr'])->name('edit')->middleware('permission:Edit Top Management Form');
        Route::put('{id}', [ChangeRequestController::class, 'updateTopManagementCr'])->name('update')->middleware('permission:Edit Top Management Form');
        Route::delete('{id}', [ChangeRequestController::class, 'deleteTopManagementCr'])->name('delete')->middleware('permission:Delete Top Management CRS');
    });

    // --- Search ---
    Route::resource('searchs', SearchController::class);
    Route::controller(SearchController::class)->group(function () {
        Route::get('/search/result', 'search_result');
        Route::get('advanced/search/result', 'AdvancedSearchResult')->name('advanced.search.result');
        Route::post('advanced-search-requests/export', 'AdvancedSearchResultExport')->name('advanced.search.export');
    });

    // Note: The /search/advanced_search route was mapped to CustomFields in the original file
    Route::get('/search/advanced_search', [CustomFieldGroupTypeController::class, 'AllCustomFieldsWithSelectedByformType'])->name('advanced.search');

    // --- Custom Fields & Groups (Plural Namespace) ---
    Route::controller(CustomFieldsController::class)->group(function () {
        Route::get('/custom_fields/create', 'create')->name('custom.fields.create');
        Route::get('/custom_fields/createCF', 'createCF')->name('custom.fields.createCF');
        Route::get('/custom_fields/search', 'search')->name('custom.fields.search');
        Route::get('/custom_fields/view', 'view')->name('custom.fields.view');
        Route::get('/custom_fields/viewCF', 'viewCF')->name('custom.fields.viewCF');
        Route::get('/custom_fields/viewupdate', 'viewupdate')->name('custom.fields.viewupdate');
        Route::get('custom-fields/load', 'loadCustomFields');

        // Special View Group Routes
        Route::get('groups/list/child', 'special')->defaults('parent', true)->name('custom.fields.special');
        Route::get('groups/list/specialview', 'specialview')->defaults('parent', true)->name('custom.fields.special.view');
        Route::get('groups/list/specialviewresult', 'specialviewresult')->defaults('parent', true)->name('custom.fields.special.viewresult');
        Route::get('groups/list/specialviewupdate', 'specialviewupdate')->defaults('parent', true)->name('custom.fields.special.viewupdate');
        Route::get('groups/list/specialviewsearch', 'specialviewsearch')->defaults('parent', true)->name('custom.fields.special.viewsearch');
        Route::get('groups/list/specialviewadvanced', 'specialviewadvanced')->defaults('parent', true)->name('custom.fields.special.viewadvanced');
    });

    Route::controller(CustomFieldGroupTypeController::class)->group(function () {
        Route::get('customs/field/group/type/selected/{form_type?}', 'AllCustomFieldsWithSelectedWithFormType');
        Route::post('custom/field/group/type', 'store')->name('custom.fields.store');
        Route::get('customs/field/special', 'AllCustomFieldsSelected');
    });

    // --- Admin & Configuration ---

    // Users
    Route::resource('users', UserController::class);
    Route::post('users/export-table', [UserController::class, 'exportTable'])->name('export.users.table');
    Route::post('user/updateactive', [UserController::class, 'updateactive']);

    // Groups
    Route::resource('groups', GroupController::class);
    Route::post('groups/updateactive', [GroupController::class, 'updateactive']);
    Route::get('group/statuses/{id}', [GroupController::class, 'GroupStatuses']);
    Route::post('group/store/statuses/{id}', [GroupController::class, 'StoreGroupStatuses']);

    // Statuses
    Route::resource('statuses', StatusController::class);
    Route::get('statuses/export', [StatusController::class, 'export'])->name('statuses.export');
    Route::post('status/updateactive', [StatusController::class, 'updateactive']);

    // Division Managers
    Route::resource('division_manager', Division_managerController::class);
    Route::post('/check-division-manager', [Division_managerController::class, 'ActiveDirectoryCheck']);

    // Directors
    Route::resource('directors', DirectorController::class)->except(['show', 'destroy']);
    Route::post('directors/updateactive', [DirectorController::class, 'updateStatus'])->name('directors.updateStatus');

    // Custom Fields (Resource / Singular Namespace)
    Route::resource('custom-fields', CustomFieldController::class)->except(['show', 'destroy']);
    Route::post('custom-fields/updateactive', [CustomFieldController::class, 'updateStatus'])->name('custom-fields.updateStatus');
    Route::get('custom-fields/get-table-options', [CustomFieldController::class, 'getTableOptions'])->name('custom-fields.get-table-options');

    // Hold Reasons
    Route::resource('hold-reasons', HoldReasonController::class)->except(['show', 'destroy']);
    Route::post('hold-reasons/update-status', [HoldReasonController::class, 'updateStatus'])->name('hold-reasons.update-status');

    // KPI Types & Pillars
    Route::resource('kpi-types', KpiTypeController::class)->except(['show', 'destroy']);
    Route::post('kpi-types/update-status', [KpiTypeController::class, 'updateStatus'])->name('kpi-types.update-status');

    Route::resource('kpi-pillars', KpiPillarController::class)->except(['show', 'destroy']);
    Route::post('kpi-pillars/update-status', [KpiPillarController::class, 'updateStatus'])->name('kpi-pillars.update-status');

    Route::resource('kpi-initiatives', KpiInitiativeController::class)->except(['show', 'destroy']);
    Route::post('kpi-initiatives/update-status', [KpiInitiativeController::class, 'updateStatus'])->name('kpi-initiatives.update-status');

    Route::resource('kpi-sub-initiatives', KpiSubInitiativeController::class)->except(['show', 'destroy']);
    Route::post('kpi-sub-initiatives/update-status', [KpiSubInitiativeController::class, 'updateStatus'])->name('kpi-sub-initiatives.update-status');

    // Units
    Route::resource('units', UnitController::class)->except(['show', 'destroy']);
    Route::post('units/updateactive', [UnitController::class, 'updateStatus'])->name('units.updateStatus');

    // Stages
    Route::resource('stages', StageController::class);
    Route::post('stage/updateactive', [StageController::class, 'updateactive']);

    // Requester Departments
    Route::resource('requester-department', RequesterDepartmentController::class);
    Route::post('requester-department/updateactive', [RequesterDepartmentController::class, 'updateactive'])->name('requester-department.update-active');

    // Parents
    Route::resource('parents', ParentController::class);
    Route::post('parent/updateactive', [ParentController::class, 'updateactive']);
    Route::get('list/CRs/by/workflowtype', [ParentController::class, 'ListCRsbyWorkflowtype']);
    Route::get('parent/file/download/{id}', [ParentController::class, 'download'])->name('parent.download');

    // High Level Statuses
    Route::resource('high_level_status', highLevelStatusesControlller::class);
    Route::post('high_level_status/updateactive', [highLevelStatusesControlller::class, 'updateactive']);

    // Workflows
    Route::resource('NewWorkFlowController', NewWorkFlowController::class);
    Route::controller(NewWorkFlowController::class)->group(function () {
        Route::get('workflow/list/all', 'ListAllWorkflows');
        Route::get('workflow/same/from/status', 'SameFromWorkflow');
        Route::post('workflow2/updateactive', 'updateactive');
        Route::get('workflow/export', 'exportWorkflows')->name('workflow.export');
    });

    // Applications
    Route::resource('applications', ApplicationController::class);
    Route::post('application/updateactive', [ApplicationController::class, 'updateactive']);
    Route::get('app/file/download/{id}', [ApplicationController::class, 'download'])->name('app.download');

    // Rejection Reasons
    Route::resource('rejection_reasons', RejectionReasonsController::class);
    Route::post('rejection_reasons/updateactive', [RejectionReasonsController::class, 'updateactive']);

    // Roles & Permissions
    Route::resource('roles', RolesController::class);
    Route::resource('permissions', PermissionsController::class);

    // Notification Templates
    Route::resource('notification_templates', NotificationTemplatesController::class);

    // Prerequisites
    Route::resource('prerequisites', PrerequisitesController::class);
    Route::get('prerequisites/download/{id}', [PrerequisitesController::class, 'download'])->name('prerequisites.download');

    // CAB Users
    Route::resource('cab_users', CabUserController::class);
    Route::post('cab_user/updateactive', [CabUserController::class, 'updateactive']);

    // Final Confirmation
    Route::get('final-confirmation', [FinalConfirmationController::class, 'index'])->name('final_confirmation.index');
    Route::post('final-confirmation/submit', [FinalConfirmationController::class, 'submit'])->name('final_confirmation.submit');

    // Configuration Generic
    Route::get('/configurations', [ConfigurationController::class, 'index'])->name('configurations.index');
    Route::post('/configurations/update', [ConfigurationController::class, 'update'])->name('configurations.update');

    // --- Releases ---
    Route::resource('releases', ReleaseController::class);
    Route::controller(ReleaseController::class)->group(function () {
        Route::get('releases/show_release/{id}', 'show_release');
        Route::get('release/logs/{id}', 'ReleaseLogs');
        Route::get('update_release_its_crs', 'update_release_its_crs');
    });

    Route::controller(CRSReleaseController::class)->group(function () {
        Route::get('releases/show_crs/asd', 'show_crs');
        Route::get('releases/home', 'reorderhome');
    });

    // --- Defects ---
    Route::controller(DefectController::class)->group(function () {
        Route::get('create_defect/cr_id/{id}', 'Create');
        Route::post('store_defect', 'store');
        Route::get('edit_defect/{id}', 'edit');
        Route::get('defect/files/download/{id}', 'download');
        Route::patch('defect_update/{id}', 'update');
        Route::get('defects', 'index');
        Route::get('show_defect/{id}', 'show');
    });

    // --- SLAs ---
    Route::resource('sla-calculations', SlaCalculationController::class);
    Route::get('/get-groups/{status_id}', [SlaCalculationController::class, 'getGroups'])->name('get.groups');

    // --- KPIs ---
    Route::resource('kpis', KPIController::class);
    Route::controller(KPIController::class)->prefix('kpis')->name('kpis.')->group(function () {
        Route::get('/export', 'export')->name('export');
        Route::get('/get-initiatives', 'getInitiativesByPillar')->name('get-initiatives');
        Route::get('/get-sub-initiatives', 'getSubInitiativesByInitiative')->name('get-sub-initiatives');
        Route::post('/check-requester-email', 'checkRequesterEmail')->name('check-requester-email');
        Route::get('/{kpi}/search-cr', 'searchChangeRequest')->name('search-cr');
        Route::post('/{kpi}/attach-cr', 'attachChangeRequest')->name('attach-cr');
        Route::delete('/{kpi}/detach-cr/{cr}', 'detachChangeRequest')->name('detach-cr');
        Route::get('/{kpi}/export-crs', 'exportChangeRequests')->name('export-crs');
    });

    // KPI Project
    Route::post('kpis/{kpi}/projects/{project}', [KpiProjectController::class, 'attach'])->name('kpi-projects.attach');
    Route::delete('kpis/{kpi}/projects/{project}', [KpiProjectController::class, 'detach'])->name('kpi-projects.detach');

    // Projects (KPI Related)
    Route::resource('projects', ProjectController::class);
    Route::post('projects/delete-milestone', [ProjectController::class, 'deleteMilestone'])->name('projects.delete-milestone');
    Route::get('projects-export', [ProjectController::class, 'export'])->name('projects.export');
    Route::get('projects-export/kpi/{kpi}', [ProjectController::class, 'exportByKpi'])->name('projects.export-by-kpi');

    // --- Reports ---
    Route::prefix('reports')->group(function () {
        Route::get('/actual-vs-planned', [ReportController::class, 'actualVsPlanned'])->name('reports.actual_vs_planned');
        Route::get('/all-crs-by-requester', [ReportController::class, 'allCrsByRequester'])->name('reports.all_crs_by_requester');
        Route::get('/cr-current-status', [ReportController::class, 'crCurrentStatus'])->name('reports.cr_current_status');
        Route::get('/cr-crossed-sla', [ReportController::class, 'crCrossedSla'])->name('reports.cr_crossed_sla');
        Route::get('/rejected-crs', [ReportController::class, 'rejectedCrs'])->name('reports.rejected_crs');

        Route::post('/cr-current-status', [ReportController::class, 'crCurrentStatus'])->name('report.current-status');
        Route::post('/cr-current-status/export', [ReportController::class, 'exportCurrentStatus'])->name('report.current-status.export');

        Route::post('/all-crs-by-requester/export', [ReportController::class, 'exportAllCrsByRequester'])->name('all.crs.by.requester.export');
    });

    Route::get('/actual-vs-planned', [ReportController::class, 'actualVsPlanned'])->name('actual.vs.planned');
    Route::get('/report/crs-crossed-sla/export', [ReportController::class, 'exportCrsCrossedSla'])->name('report.cross_sla.export');
    Route::get('/report/rejected-crs/export', [ReportController::class, 'exportRejectedCrs'])->name('report.rejected_crs.export');

});
