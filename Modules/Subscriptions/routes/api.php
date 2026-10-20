<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscriptions\Http\Controllers\Admin\FeatureController;
use Modules\Subscriptions\Http\Controllers\Admin\PlanCatalogController;
use Modules\Subscriptions\Http\Controllers\Admin\PlanVersionController;
use Modules\Subscriptions\Http\Controllers\Admin\SubscriptionAnalyticsController;
use Modules\Subscriptions\Http\Controllers\Admin\SubscriptionAuditController;
use Modules\Subscriptions\Http\Controllers\Admin\SubscriptionPolicyController;
use Modules\Subscriptions\Http\Controllers\Admin\TenantSubscriptionController;
use Modules\Subscriptions\Http\Controllers\Admin\UpgradeRequestController;
use Modules\Subscriptions\Http\Controllers\Admin\BillingAdminController;
use Modules\Subscriptions\Http\Controllers\Admin\BillingOperationsController;
use Modules\Subscriptions\Http\Controllers\Admin\BillingAuditController;
use Modules\Subscriptions\Http\Controllers\Admin\BillingInvestigationController;
use Modules\Subscriptions\Http\Controllers\Admin\BillingTenantAgreementController;
use Modules\Subscriptions\Http\Controllers\Admin\BillingPromotionController;
use Modules\Subscriptions\Http\Controllers\BillingWebhookController;
use Modules\Subscriptions\Http\Controllers\PublicPlanController;
use Modules\Subscriptions\Http\Controllers\Tenant\TenantBillingController;
use Modules\Subscriptions\Http\Controllers\Tenant\TenantEntitlementController;
use Modules\Subscriptions\Http\Controllers\Tenant\TenantUpgradeRequestController;

/*
 *--------------------------------------------------------------------------
 * API Routes - Subscriptions Module
 *--------------------------------------------------------------------------
 *
 * admin/subscriptions/*  platform actors only (SuperAdmin / EkklesiaAdmin with permission)
 * tenant/*               authenticated tenant user; tenant resolved from TenantContext
 * public/*               unauthenticated pricing catalog
 */

Route::get('/public/subscription-plans', [PublicPlanController::class, 'index'])
    ->middleware('api.throttle:public_catalog')
    ->name('subscriptions.public.plans');

Route::post('/billing/webhooks/razorpay', [BillingWebhookController::class, 'razorpay'])
    ->middleware('api.throttle:public_forms')
    ->name('subscriptions.billing.webhooks.razorpay');

Route::middleware('auth:api')->group(function () {
    Route::prefix('tenant')->name('subscriptions.tenant.')->group(function () {
        Route::get('/entitlements', [TenantEntitlementController::class, 'entitlements'])->name('entitlements');
        Route::get('/subscription/overview', [TenantEntitlementController::class, 'subscription'])->name('overview');
        Route::get('/subscription/comparison', [TenantEntitlementController::class, 'comparison'])->name('comparison');
        Route::post('/subscription/preview-plan-change', [TenantEntitlementController::class, 'previewPlanChange'])->name('preview-plan-change');
        Route::get('/subscription/usage', [TenantEntitlementController::class, 'usage'])->name('usage');
        Route::get('/subscription/upgrade-requests', [TenantUpgradeRequestController::class, 'index'])->name('upgrade-requests.index');
        Route::post('/subscription/upgrade-requests', [TenantUpgradeRequestController::class, 'store'])
            ->middleware('throttle:10,60')->name('upgrade-requests.store');

        Route::prefix('billing')->name('billing.')->group(function () {
            Route::get('/availability', [TenantBillingController::class, 'availability'])->name('availability');
            Route::post('/quotes', [TenantBillingController::class, 'quote'])->middleware('throttle:30,1')->name('quotes');
            Route::post('/orders', [TenantBillingController::class, 'createOrder'])->middleware('throttle:20,1')->name('orders.store');
            Route::get('/orders/{order}', [TenantBillingController::class, 'showOrder'])->whereNumber('order')->name('orders.show');
            Route::post('/orders/{order}/verify', [TenantBillingController::class, 'verify'])->whereNumber('order')->middleware('throttle:30,1')->name('orders.verify');
            Route::get('/invoices', [TenantBillingController::class, 'invoices'])->name('invoices.index');
            Route::get('/credit-notes', [TenantBillingController::class, 'creditNotes'])->name('credit-notes.index');
            Route::get('/credit-notes/{creditNote}/download', [TenantBillingController::class, 'downloadCreditNote'])
                ->whereNumber('creditNote')->name('credit-notes.download');
            Route::get('/invoices/{invoice}', [TenantBillingController::class, 'showInvoice'])->whereNumber('invoice')->name('invoices.show');
            Route::get('/invoices/{invoice}/download', [TenantBillingController::class, 'downloadInvoice'])->whereNumber('invoice')->name('invoices.download');
            Route::post('/offline-claims', [TenantBillingController::class, 'submitOfflineClaim'])->middleware('throttle:10,60')->name('offline-claims.store');
            Route::post('/offline-claims/{claim}/evidence', [TenantBillingController::class, 'uploadOfflineClaimEvidence'])
                ->whereNumber('claim')->middleware('throttle:10,60')->name('offline-claims.evidence');
            Route::post('/payable-orders', [TenantBillingController::class, 'createPayableOrder'])->middleware('throttle:20,1')->name('payable-orders.store');
            Route::get('/offline-instructions', [TenantBillingController::class, 'offlineInstructions'])->name('offline-instructions');
        });
    });

    Route::prefix('admin/subscriptions')->name('subscriptions.admin.')->group(function () {
        $view = 'subscriptions.platform.permission:subscriptions.plans.view';
        $manage = 'subscriptions.platform.permission:subscriptions.plans.manage';
        $publish = 'subscriptions.platform.permission:subscriptions.plans.publish';
        $features = 'subscriptions.platform.permission:subscriptions.features.manage';

        Route::get('/plans', [PlanCatalogController::class, 'index'])->middleware($view)->name('plans.index');
        Route::get('/plans/{plan}', [PlanCatalogController::class, 'show'])->middleware($view)->whereNumber('plan')->name('plans.show');
        Route::post('/plans', [PlanCatalogController::class, 'store'])->middleware($manage)->name('plans.store');
        Route::patch('/plans/{plan}', [PlanCatalogController::class, 'update'])->middleware($manage)->whereNumber('plan')->name('plans.update');
        Route::delete('/plans/{plan}', [PlanCatalogController::class, 'destroy'])
            ->middleware('subscriptions.platform.permission:subscriptions.plans.delete')->whereNumber('plan')->name('plans.destroy');
        Route::post('/plans/{plan}/archive', [PlanCatalogController::class, 'archive'])->middleware($publish)->whereNumber('plan')->name('plans.archive');
        Route::post('/plans/{plan}/restore', [PlanCatalogController::class, 'restore'])->middleware($publish)->whereNumber('plan')->name('plans.restore');
        Route::post('/plans/{plan}/duplicate', [PlanCatalogController::class, 'duplicate'])->middleware($manage)->whereNumber('plan')->name('plans.duplicate');
        Route::get('/plans/{plan}/tenants', [PlanCatalogController::class, 'tenants'])
            ->middleware('subscriptions.platform.permission:subscriptions.usage.view')->whereNumber('plan')->name('plans.tenants');
        Route::get('/matrix', [PlanCatalogController::class, 'matrix'])->middleware($view)->name('matrix');

        Route::prefix('/plans/{plan}/versions')->whereNumber('plan')->name('versions.')->group(function () use ($view, $manage, $publish) {
            Route::post('/', [PlanVersionController::class, 'store'])->middleware($manage)->name('store');
            Route::get('/{version}', [PlanVersionController::class, 'show'])->middleware($view)->whereNumber('version')->name('show');
            Route::get('/{version}/impact', [PlanVersionController::class, 'impact'])->middleware($view)->whereNumber('version')->name('impact');
            Route::get('/{version}/preview', [PlanVersionController::class, 'preview'])->middleware($view)->whereNumber('version')->name('preview');
            Route::post('/{version}/migrate-tenants', [PlanVersionController::class, 'migrateTenants'])
                ->middleware('subscriptions.platform.permission:subscriptions.tenants.manage')->whereNumber('version')->name('migrate-tenants');
            Route::patch('/{version}', [PlanVersionController::class, 'update'])->middleware($manage)->whereNumber('version')->name('update');
            Route::put('/{version}/entitlements', [PlanVersionController::class, 'entitlements'])->middleware($manage)->whereNumber('version')->name('entitlements');
            Route::delete('/{version}', [PlanVersionController::class, 'destroy'])->middleware($manage)->whereNumber('version')->name('destroy');
            Route::post('/{version}/publish', [PlanVersionController::class, 'publish'])->middleware($publish)->whereNumber('version')->name('publish');
            Route::post('/{version}/unschedule', [PlanVersionController::class, 'unschedule'])->middleware($publish)->whereNumber('version')->name('unschedule');
            Route::post('/{version}/retire', [PlanVersionController::class, 'retire'])->middleware($publish)->whereNumber('version')->name('retire');
        });

        Route::get('/features', [FeatureController::class, 'index'])->middleware($view)->name('features.index');
        Route::post('/features', [FeatureController::class, 'store'])->middleware($features)->name('features.store');
        Route::patch('/features/{feature}', [FeatureController::class, 'update'])->middleware($features)->whereNumber('feature')->name('features.update');
        Route::put('/features/{feature}/dependencies', [FeatureController::class, 'dependencies'])->middleware($features)->whereNumber('feature')->name('features.dependencies');

        Route::prefix('/tenants/{tenant}')->whereNumber('tenant')->name('tenants.')->group(function () {
            Route::get('/', [TenantSubscriptionController::class, 'show'])
                ->middleware('subscriptions.platform.permission:subscriptions.usage.view')->name('show');
            Route::post('/preview', [TenantSubscriptionController::class, 'preview'])
                ->middleware('subscriptions.platform.permission:subscriptions.tenants.manage')->name('preview');
            Route::post('/assign', [TenantSubscriptionController::class, 'assign'])
                ->middleware('subscriptions.platform.permission:subscriptions.tenants.manage')->name('assign');
            Route::post('/pending/cancel', [TenantSubscriptionController::class, 'cancelPending'])
                ->middleware('subscriptions.platform.permission:subscriptions.tenants.manage')->name('pending.cancel');
            Route::post('/overrides', [TenantSubscriptionController::class, 'grantOverride'])
                ->middleware('subscriptions.super_admin.override')->name('overrides.grant');
            Route::post('/overrides/{override}/revoke', [TenantSubscriptionController::class, 'revokeOverride'])
                ->middleware('subscriptions.super_admin.override')->whereNumber('override')->name('overrides.revoke');
        });

        Route::get('/policies', [SubscriptionPolicyController::class, 'show'])->middleware($view)->name('policies.show');
        Route::put('/policies', [SubscriptionPolicyController::class, 'update'])
            ->middleware('subscriptions.platform.permission:subscriptions.policies.manage')->name('policies.update');

        Route::get('/tax', [SubscriptionPolicyController::class, 'showTax'])->middleware($view)->name('tax.show');
        Route::put('/tax', [SubscriptionPolicyController::class, 'updateTax'])
            ->middleware('subscriptions.platform.permission:subscriptions.policies.manage')->name('tax.update');
        Route::post('/tax/preview', [SubscriptionPolicyController::class, 'previewTax'])
            ->middleware('subscriptions.platform.permission:subscriptions.policies.manage')->name('tax.preview');

        $usage = 'subscriptions.platform.permission:subscriptions.usage.view';
        $review = 'subscriptions.platform.permission:subscriptions.requests.review';
        Route::get('/overview', [SubscriptionAnalyticsController::class, 'overview'])->middleware($usage)->name('overview');
        Route::get('/usage', [SubscriptionAnalyticsController::class, 'usage'])->middleware($usage)->name('usage');
        Route::get('/revenue', [SubscriptionAnalyticsController::class, 'revenue'])
            ->middleware('subscriptions.platform.permission:subscriptions.revenue.view')->name('revenue');

        Route::get('/upgrade-requests', [UpgradeRequestController::class, 'index'])->middleware($review)->name('upgrade-requests.index');
        Route::post('/upgrade-requests/{upgradeRequest}/approve', [UpgradeRequestController::class, 'approve'])
            ->middleware([$review, 'subscriptions.platform.permission:subscriptions.tenants.manage'])->whereNumber('upgradeRequest')->name('upgrade-requests.approve');
        Route::post('/upgrade-requests/{upgradeRequest}/reject', [UpgradeRequestController::class, 'reject'])
            ->middleware($review)->whereNumber('upgradeRequest')->name('upgrade-requests.reject');
        Route::post('/upgrade-requests/{upgradeRequest}/request-info', [UpgradeRequestController::class, 'requestInfo'])
            ->middleware($review)->whereNumber('upgradeRequest')->name('upgrade-requests.request-info');

        Route::get('/audits', [SubscriptionAuditController::class, 'catalog'])
            ->middleware('subscriptions.platform.permission:subscriptions.audit.view')->name('audits.catalog');

        $billingView = 'subscriptions.platform.permission:subscriptions.billing.view';
        $gateway = 'subscriptions.platform.permission:subscriptions.gateway.manage';
        $payments = 'subscriptions.platform.permission:subscriptions.payments.view';
        $refunds = 'subscriptions.platform.permission:subscriptions.refunds.approve';
        $reconciliation = 'subscriptions.platform.permission:subscriptions.reconciliation.run';
        $adjustments = 'subscriptions.platform.permission:subscriptions.adjustments.manage';
        Route::prefix('billing')->name('billing.')->group(function () use ($billingView, $gateway, $payments, $refunds, $reconciliation, $adjustments) {
            Route::get('/controls', [BillingAdminController::class, 'showControls'])->middleware($billingView)->name('controls.show');
            Route::put('/controls', [BillingAdminController::class, 'updateControls'])->middleware($gateway)->name('controls.update');
            Route::put('/gateways/{environment}', [BillingAdminController::class, 'updateGateway'])->middleware($gateway)->name('gateways.update');
            Route::post('/gateways/{environment}/validate', [BillingAdminController::class, 'validateGateway'])->middleware($gateway)->name('gateways.validate');
            Route::get('/offline-claims', [BillingAdminController::class, 'offlineClaimsIndex'])
                ->middleware('subscriptions.platform.permission:subscriptions.offline.review')->name('offline-claims.index');
            Route::get('/offline-claims/{claim}', [BillingAdminController::class, 'showOfflineClaim'])
                ->middleware('subscriptions.platform.permission:subscriptions.offline.review')->whereNumber('claim')->name('offline-claims.show');
            Route::get('/offline-methods', [BillingAdminController::class, 'offlineMethodsIndex'])->middleware($billingView)->name('offline-methods.index');
            Route::patch('/offline-methods/{method}', [BillingAdminController::class, 'updateOfflineMethod'])->middleware($gateway)->whereNumber('method')->name('offline-methods.update');
            Route::post('/offline-claims/{claim}/approve', [BillingAdminController::class, 'approveOfflineClaim'])
                ->middleware('subscriptions.platform.permission:subscriptions.offline.review')->whereNumber('claim')->name('offline-claims.approve');
            Route::post('/offline-claims/{claim}/reject', [BillingAdminController::class, 'rejectOfflineClaim'])
                ->middleware('subscriptions.platform.permission:subscriptions.offline.review')->whereNumber('claim')->name('offline-claims.reject');
            Route::post('/offline-claims/{claim}/request-info', [BillingAdminController::class, 'requestOfflineClaimInfo'])
                ->middleware('subscriptions.platform.permission:subscriptions.offline.review')->whereNumber('claim')->name('offline-claims.request-info');
            Route::get('/tenant-price-assignments', [BillingAdminController::class, 'tenantPriceAssignmentsIndex'])
                ->middleware($adjustments)->name('tenant-price-assignments.index');
            Route::post('/tenant-price-assignments', [BillingAdminController::class, 'assignTenantPrice'])
                ->middleware($adjustments)->name('tenant-price-assignments.store');
            Route::post('/tenant-price-assignments/{assignment}/revoke', [BillingAdminController::class, 'revokeTenantPriceAssignment'])
                ->middleware($adjustments)->whereNumber('assignment')->name('tenant-price-assignments.revoke');

            Route::get('/promotions', [BillingPromotionController::class, 'index'])->middleware($billingView)->name('promotions.index');
            Route::post('/promotions', [BillingPromotionController::class, 'store'])->middleware($adjustments)->name('promotions.store');
            Route::get('/promotions/{promotion}', [BillingPromotionController::class, 'show'])->middleware($billingView)->whereNumber('promotion')->name('promotions.show');
            Route::patch('/promotions/{promotion}', [BillingPromotionController::class, 'update'])->middleware($adjustments)->whereNumber('promotion')->name('promotions.update');
            Route::post('/promotions/{promotion}/status', [BillingPromotionController::class, 'setStatus'])->middleware($adjustments)->whereNumber('promotion')->name('promotions.status');

            Route::get('/orders', [BillingOperationsController::class, 'ordersIndex'])->middleware($payments)->name('orders.index');
            Route::get('/orders/{order}', [BillingOperationsController::class, 'orderShow'])->middleware($payments)->whereNumber('order')->name('orders.show');
            Route::get('/invoices', [BillingOperationsController::class, 'invoicesIndex'])->middleware($payments)->name('invoices.index');
            Route::get('/invoices/{invoice}', [BillingOperationsController::class, 'invoiceShow'])->middleware($payments)->whereNumber('invoice')->name('invoices.show');
            Route::get('/invoices/{invoice}/download', [BillingOperationsController::class, 'adminDownloadInvoice'])->middleware($payments)->whereNumber('invoice')->name('invoices.download');

            Route::get('/refunds', [BillingOperationsController::class, 'refundsIndex'])->middleware($refunds)->name('refunds.index');
            Route::post('/refunds', [BillingOperationsController::class, 'requestRefund'])->middleware($refunds)->name('refunds.store');
            Route::post('/refunds/{refund}/approve', [BillingOperationsController::class, 'approveRefund'])->middleware($refunds)->whereNumber('refund')->name('refunds.approve');
            Route::post('/credit-notes', [BillingOperationsController::class, 'issueCreditNote'])->middleware($adjustments)->name('credit-notes.store');
            Route::get('/credit-notes/{creditNote}/download', [BillingOperationsController::class, 'adminDownloadCreditNote'])
                ->middleware($payments)->whereNumber('creditNote')->name('credit-notes.download');
            Route::get('/operations/summary', [BillingOperationsController::class, 'operationsSummary'])
                ->middleware($billingView)->name('operations.summary');

            Route::get('/tenant-agreements', [BillingTenantAgreementController::class, 'index'])
                ->middleware($adjustments)->name('tenant-agreements.index');
            Route::post('/tenant-agreements', [BillingTenantAgreementController::class, 'store'])
                ->middleware('subscriptions.platform.permission:'.\Modules\Subscriptions\Support\SubscriptionsPermissionCatalog::AGREEMENTS_MANAGE)
                ->name('tenant-agreements.store');
            Route::post('/tenant-agreements/{agreement}/revoke', [BillingTenantAgreementController::class, 'revoke'])
                ->middleware('subscriptions.platform.permission:'.\Modules\Subscriptions\Support\SubscriptionsPermissionCatalog::AGREEMENTS_MANAGE)
                ->whereNumber('agreement')->name('tenant-agreements.revoke');

            Route::get('/webhooks/health', [BillingOperationsController::class, 'webhookHealth'])->middleware($billingView)->name('webhooks.health');
            Route::post('/reconciliation/runs', [BillingOperationsController::class, 'runReconciliation'])->middleware($reconciliation)->name('reconciliation.run');

            Route::get('/audit-events', [BillingAuditController::class, 'index'])
                ->middleware('subscriptions.platform.permission:subscriptions.audit.view')->name('audit-events.index');

            Route::get('/investigations', [BillingInvestigationController::class, 'index'])
                ->middleware($payments)->name('investigations.index');
            Route::post('/investigations/{investigation}/resolve', [BillingInvestigationController::class, 'resolve'])
                ->middleware($payments)->whereNumber('investigation')->name('investigations.resolve');
        });
    });
});
