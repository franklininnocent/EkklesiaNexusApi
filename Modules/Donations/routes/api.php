<?php

use Illuminate\Support\Facades\Route;
use Modules\Donations\Http\Controllers\CollectionForecastController;
use Modules\Donations\Http\Controllers\ContributionDuesController;
use Modules\Donations\Http\Controllers\ContributionPlanAssignmentsController;
use Modules\Donations\Http\Controllers\ContributionPlansController;
use Modules\Donations\Http\Controllers\ContributionScheduleController;
use Modules\Donations\Http\Controllers\DonationApprovalsController;
use Modules\Donations\Http\Controllers\DonationAuditLogsController;
use Modules\Donations\Http\Controllers\DonationCampaignsController;
use Modules\Donations\Http\Controllers\DonationCategoriesController;
use Modules\Donations\Http\Controllers\DonationDashboardController;
use Modules\Donations\Http\Controllers\DonationNotificationsController;
use Modules\Donations\Http\Controllers\DonationPaymentsController;
use Modules\Donations\Http\Controllers\DonationProjectsController;
use Modules\Donations\Http\Controllers\DonationReceiptsController;
use Modules\Donations\Http\Controllers\DonationReportsController;
use Modules\Donations\Http\Controllers\DonationSavedViewsController;
use Modules\Donations\Http\Controllers\DonationsController;
use Modules\Donations\Http\Controllers\DonationSettingsController;
use Modules\Donations\Http\Controllers\DonorsController;
use Modules\Donations\Http\Controllers\FinancialAiController;
use Modules\Donations\Http\Controllers\FinancialGlobalSearchController;
use Modules\Donations\Http\Controllers\FundsController;
use Modules\Donations\Http\Controllers\OperationsDashboardController;
use Modules\Donations\Http\Controllers\ParishExpensesController;
use Modules\Donations\Http\Controllers\PaymentBatchesController;
use Modules\Donations\Http\Controllers\PaymentOcrController;
use Modules\Donations\Http\Controllers\PaymentWebhooksController;
use Modules\Donations\Http\Controllers\ProjectFamilyAssignmentsController;
use Modules\Donations\Http\Controllers\ProjectInstallmentDuesController;
use Modules\Donations\Http\Controllers\RecurringDonationSchedulesController;
use Modules\Donations\Http\Controllers\UpiPaymentController;
use Modules\Donations\Http\Controllers\WhatsAppOutreachController;

Route::prefix('tenant/donations')->middleware(['donations.security.log', 'auth:api', 'tenant.feature.donations', 'tenant.permission:donations.view'])->group(function () {
    Route::get('/dashboard/summary', [DonationDashboardController::class, 'summary']);
    Route::get('/dashboard/command-center', [DonationDashboardController::class, 'commandCenter'])->middleware('entitlement:FINANCIAL_DASHBOARD');
    Route::get('/dashboard/operations', [OperationsDashboardController::class, 'summary']);
    Route::get('/activity/timeline', [OperationsDashboardController::class, 'timeline']);
    Route::get('/dashboard/rollup', [DonationDashboardController::class, 'rollup'])->middleware('entitlement:FINANCIAL_DASHBOARD');
    Route::get('/dashboard/families/{familyId}/summary', [DonationDashboardController::class, 'familySummary']);
    Route::get('/families/{familyId}/financial-profile', [DonationDashboardController::class, 'familyFinancialProfile']);
    Route::get('/families/{familyId}/collect-dues', [DonationDashboardController::class, 'familyCollectDues']);
    Route::get('/families/{familyId}/financial-profile/print', [DonationDashboardController::class, 'familyStatementPrint']);
    Route::post('/ai/ask', [FinancialAiController::class, 'ask']);
    Route::get('/ai/status', [FinancialAiController::class, 'status']);
    Route::get('/dashboard/forecast', [CollectionForecastController::class, 'show'])->middleware('entitlement:FINANCIAL_DASHBOARD');
    Route::get('/upi/intent', [UpiPaymentController::class, 'intent']);
    Route::post('/payments/ocr-scan', [PaymentOcrController::class, 'scan'])->middleware('tenant.permission:donations.collect');
    Route::get('/outreach/whatsapp/preview', [WhatsAppOutreachController::class, 'preview'])->middleware('tenant.permission:donations.notifications');
    Route::post('/outreach/whatsapp/queue', [WhatsAppOutreachController::class, 'queue'])->middleware('tenant.permission:donations.notifications');
    Route::post('/outreach/whatsapp/deliver-pending', [WhatsAppOutreachController::class, 'deliverPending'])->middleware('tenant.permission:donations.notifications');
    Route::get('/outreach/whatsapp/delivery-summary', [WhatsAppOutreachController::class, 'deliverySummary'])->middleware('tenant.permission:donations.notifications');

    Route::get('/search', [FinancialGlobalSearchController::class, 'search']);
    Route::get('/saved-views', [DonationSavedViewsController::class, 'index']);
    Route::get('/saved-views/{key}', [DonationSavedViewsController::class, 'apply']);

    Route::get('/settings', [DonationSettingsController::class, 'show']);
    Route::put('/settings', [DonationSettingsController::class, 'update'])->middleware('tenant.permission:church.settings.edit');

    Route::get('/categories', [DonationCategoriesController::class, 'index']);
    Route::post('/categories', [DonationCategoriesController::class, 'store'])->middleware('tenant.permission:donations.manage');
    Route::post('/categories/seed-defaults', [DonationCategoriesController::class, 'seedDefaults'])->middleware('tenant.permission:donations.manage');
    Route::put('/categories/{id}', [DonationCategoriesController::class, 'update'])->middleware('tenant.permission:donations.manage');
    Route::delete('/categories/{id}', [DonationCategoriesController::class, 'destroy'])->middleware('tenant.permission:donations.manage');

    Route::get('/donors', [DonorsController::class, 'index']);
    Route::get('/donors/{id}', [DonorsController::class, 'show']);
    Route::post('/donors', [DonorsController::class, 'store'])->middleware('tenant.permission:donations.manage');
    Route::put('/donors/{id}', [DonorsController::class, 'update'])->middleware('tenant.permission:donations.manage');

    Route::get('/entries', [DonationsController::class, 'index']);
    Route::get('/entries/{id}', [DonationsController::class, 'show']);
    Route::post('/entries', [DonationsController::class, 'store'])->middleware('tenant.permission:donations.collect');
    Route::put('/entries/{id}', [DonationsController::class, 'update'])->middleware('tenant.permission:donations.manage');
    Route::post('/entries/collect', [DonationsController::class, 'collect'])->middleware('tenant.permission:donations.collect');

    Route::get('/funds', [FundsController::class, 'index']);
    Route::post('/funds', [FundsController::class, 'store'])->middleware('tenant.permission:donations.manage');

    Route::get('/plans', [ContributionPlansController::class, 'index'])->middleware('entitlement:CONTRIBUTION_PLANS');
    Route::get('/plans/{id}', [ContributionPlansController::class, 'show'])->middleware('entitlement:CONTRIBUTION_PLANS');
    Route::post('/plans', [ContributionPlansController::class, 'store'])->middleware(['tenant.permission:donations.manage', 'entitlement:CONTRIBUTION_PLANS']);
    Route::put('/plans/{id}', [ContributionPlansController::class, 'update'])->middleware(['tenant.permission:donations.manage', 'entitlement:CONTRIBUTION_PLANS']);
    Route::get('/plans/{id}/revision-history', [ContributionPlansController::class, 'revisionHistory'])->middleware('entitlement:CONTRIBUTION_PLANS');
    Route::get('/plans/{id}/generation-preview', [ContributionPlansController::class, 'generationPreview'])->middleware(['tenant.permission:donations.manage', 'entitlement:CONTRIBUTION_PLANS']);
    Route::post('/plans/{id}/generate-dues', [ContributionPlansController::class, 'generateDues'])->middleware(['tenant.permission:donations.manage', 'entitlement:CONTRIBUTION_PLANS']);
    Route::get('/plans/{planId}/assignments', [ContributionPlanAssignmentsController::class, 'index'])->middleware('entitlement:CONTRIBUTION_PLANS');
    Route::post('/plans/{planId}/assignments', [ContributionPlanAssignmentsController::class, 'store'])->middleware(['tenant.permission:donations.manage', 'entitlement:CONTRIBUTION_PLANS']);
    Route::post('/contributions/generate-scheduled', [ContributionScheduleController::class, 'generateScheduled'])->middleware(['tenant.permission:donations.manage', 'entitlement:CONTRIBUTION_PLANS']);

    Route::get('/projects', [DonationProjectsController::class, 'index']);
    Route::get('/projects/{id}', [DonationProjectsController::class, 'show']);
    Route::get('/projects/{id}/dashboard', [DonationProjectsController::class, 'dashboard']);
    Route::get('/projects/{id}/family-progress', [DonationProjectsController::class, 'familyProgress']);
    Route::get('/projects/{id}/installment-schedule', [DonationProjectsController::class, 'installmentSchedule']);
    Route::post('/projects', [DonationProjectsController::class, 'store'])->middleware('tenant.permission:donations.manage');
    Route::put('/projects/{id}', [DonationProjectsController::class, 'update'])->middleware('tenant.permission:donations.manage');
    Route::post('/projects/{id}/generate-installments', [DonationProjectsController::class, 'generateInstallments'])->middleware('tenant.permission:donations.manage');

    Route::get('/campaigns', [DonationCampaignsController::class, 'index']);
    Route::get('/campaigns/{id}', [DonationCampaignsController::class, 'show']);
    Route::get('/campaigns/{id}/dashboard', [DonationCampaignsController::class, 'dashboard']);
    Route::post('/campaigns', [DonationCampaignsController::class, 'store'])->middleware('tenant.permission:donations.manage');
    Route::get('/projects/{projectId}/assignments', [ProjectFamilyAssignmentsController::class, 'index']);
    Route::post('/projects/{projectId}/assignments', [ProjectFamilyAssignmentsController::class, 'store'])->middleware('tenant.permission:donations.manage');
    Route::get('/project-installments', [ProjectInstallmentDuesController::class, 'index']);
    Route::post('/project-installments/{id}/waive', [ProjectInstallmentDuesController::class, 'waive'])->middleware('tenant.permission:donations.manage');
    Route::post('/project-installments/{id}/cancel', [ProjectInstallmentDuesController::class, 'cancel'])->middleware('tenant.permission:donations.manage');

    Route::get('/dues', [ContributionDuesController::class, 'index']);
    Route::post('/dues', [ContributionDuesController::class, 'store'])->middleware('tenant.permission:donations.manage');
    Route::post('/dues/bulk-waive', [ContributionDuesController::class, 'bulkWaive'])->middleware('tenant.permission:donations.manage');
    Route::post('/dues/bulk-remind', [ContributionDuesController::class, 'bulkRemind'])->middleware('tenant.permission:donations.notifications');
    Route::post('/dues/{id}/waive', [ContributionDuesController::class, 'waive'])->middleware('tenant.permission:donations.manage');
    Route::post('/dues/{id}/cancel', [ContributionDuesController::class, 'cancel'])->middleware('tenant.permission:donations.manage');
    Route::post('/dues/{id}/remind', [ContributionDuesController::class, 'sendReminder'])->middleware('tenant.permission:donations.notifications');

    Route::get('/payments', [DonationPaymentsController::class, 'index']);
    Route::get('/payments/collect-context', [DonationPaymentsController::class, 'collectContext']);
    Route::post('/payments', [DonationPaymentsController::class, 'store'])->middleware('tenant.permission:donations.collect');
    Route::get('/expenses', [ParishExpensesController::class, 'index']);
    Route::post('/expenses', [ParishExpensesController::class, 'store'])->middleware('tenant.permission:donations.manage');
    Route::post('/payments/{id}/reverse', [DonationPaymentsController::class, 'reverse'])->middleware('tenant.permission:donations.reverse');
    Route::post('/payments/{id}/refunds', [DonationPaymentsController::class, 'requestRefund'])->middleware('tenant.permission:donations.refund');
    Route::get('/payment-batches', [PaymentBatchesController::class, 'index']);
    Route::post('/payment-batches', [PaymentBatchesController::class, 'store'])->middleware('tenant.permission:donations.collect');
    Route::post('/payment-batches/upload', [PaymentBatchesController::class, 'upload'])->middleware('tenant.permission:donations.collect');
    Route::get('/recurring-schedules', [RecurringDonationSchedulesController::class, 'index']);
    Route::post('/recurring-schedules', [RecurringDonationSchedulesController::class, 'store'])->middleware('tenant.permission:donations.collect');
    Route::put('/recurring-schedules/{id}', [RecurringDonationSchedulesController::class, 'update'])->middleware('tenant.permission:donations.collect');
    Route::post('/recurring-schedules/{id}/pause', [RecurringDonationSchedulesController::class, 'pause'])->middleware('tenant.permission:donations.collect');
    Route::post('/recurring-schedules/{id}/cancel', [RecurringDonationSchedulesController::class, 'cancel'])->middleware('tenant.permission:donations.collect');
    Route::post('/recurring-schedules/run-due', [RecurringDonationSchedulesController::class, 'runDue'])->middleware('tenant.permission:donations.collect');
    Route::post('/recurring-schedules/queue-run-due', [RecurringDonationSchedulesController::class, 'queueRunDue'])->middleware('tenant.permission:donations.collect');

    Route::get('/audit-logs', [DonationAuditLogsController::class, 'index'])->middleware('entitlement:AUDIT_LOG');

    Route::get('/payments/{paymentId}/receipt', [DonationReceiptsController::class, 'showByPayment']);
    Route::get('/payments/{paymentId}/receipt/print', [DonationReceiptsController::class, 'printByPayment']);
    Route::get('/receipts', [DonationReceiptsController::class, 'index']);
    Route::get('/receipts/{id}', [DonationReceiptsController::class, 'show']);
    Route::post('/receipts/{id}/void', [DonationReceiptsController::class, 'voidReceipt'])->middleware('tenant.permission:donations.manage');
    Route::post('/receipts/{id}/reissue', [DonationReceiptsController::class, 'reissue'])->middleware('tenant.permission:donations.manage');

    Route::get('/approvals', [DonationApprovalsController::class, 'index'])->middleware('tenant.permission:donations.approvals');
    Route::post('/approvals/{id}/decision', [DonationApprovalsController::class, 'decide'])->middleware('tenant.permission:donations.approvals');

    Route::get('/reports/catalog', [DonationReportsController::class, 'catalog'])->middleware('tenant.permission:donations.reports');
    Route::get('/reports/preview', [DonationReportsController::class, 'preview'])->middleware('tenant.permission:donations.reports');
    Route::get('/reports/print', [DonationReportsController::class, 'operationalPrint'])->middleware('tenant.permission:donations.reports');
    Route::get('/reports/exports', [DonationReportsController::class, 'exports'])->middleware('tenant.permission:donations.reports');
    Route::get('/reports/exports/{id}/download', [DonationReportsController::class, 'downloadExport'])->middleware('tenant.permission:donations.reports');
    Route::get('/reports/executive-summary', [DonationReportsController::class, 'executiveSummary'])->middleware(['tenant.permission:donations.reports', 'entitlement:ADVANCED_FINANCIAL_REPORTING']);
    Route::get('/reports/drill-down', [DonationReportsController::class, 'drillDown'])->middleware(['tenant.permission:donations.reports', 'entitlement:ADVANCED_FINANCIAL_REPORTING']);
    Route::get('/reports/parish-comparison', [DonationReportsController::class, 'parishComparison'])->middleware(['tenant.permission:donations.reports', 'entitlement:ADVANCED_FINANCIAL_REPORTING']);
    Route::get('/reports/stewardship/print', [DonationReportsController::class, 'stewardshipPrint'])->middleware('tenant.permission:donations.reports');
    Route::get('/reports/executive-board/print', [DonationReportsController::class, 'executiveBoardPrint'])->middleware(['tenant.permission:donations.reports', 'entitlement:ADVANCED_FINANCIAL_REPORTING']);
    Route::post('/reports/export', [DonationReportsController::class, 'export'])->middleware('tenant.permission:donations.reports');

    Route::get('/notifications', [DonationNotificationsController::class, 'index'])->middleware('tenant.permission:donations.notifications');
    Route::post('/notifications/reminders', [DonationNotificationsController::class, 'queueReminder'])->middleware('tenant.permission:donations.notifications');
});

Route::prefix('donations/webhooks')->middleware(['api'])->group(function (): void {
    Route::post('/{provider}', [PaymentWebhooksController::class, 'receive']);
});
