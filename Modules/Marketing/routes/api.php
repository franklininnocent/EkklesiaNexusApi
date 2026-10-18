<?php

use Illuminate\Support\Facades\Route;
use Modules\Marketing\Http\Controllers\AdminContactEnquiryController;
use Modules\Marketing\Http\Controllers\AdminMarketingContentController;
use Modules\Marketing\Http\Controllers\AdminMarketingPlanCatalogController;
use Modules\Marketing\Http\Controllers\AdminMarketingSeoMonitoringController;
use Modules\Marketing\Http\Controllers\AdminWebSettingsController;
use Modules\Marketing\Http\Controllers\PublicMarketingBffThrottleController;
use Modules\Marketing\Http\Controllers\PublicMarketingPlanPreviewController;
use Modules\Marketing\Http\Controllers\PublicContactCaptchaController;
use Modules\Marketing\Http\Controllers\PublicContactEnquiryController;
use Modules\Marketing\Http\Controllers\PublicDemoRequestController;
use Modules\Marketing\Http\Controllers\PublicMarketingContentController;
use Modules\Marketing\Http\Middleware\EnsureSuperAdmin;

Route::post('/public/demo-requests', [PublicDemoRequestController::class, 'store'])
    ->middleware('api.throttle:public_forms')
    ->name('marketing.public.demo-requests.store');

Route::get('/public/marketing-content', [PublicMarketingContentController::class, 'show'])
    ->middleware('api.throttle:public_catalog')
    ->name('marketing.public.marketing-content.show');

Route::get('/public/marketing-plan-catalog/preview', [PublicMarketingPlanPreviewController::class, 'show'])
    ->middleware('api.throttle:public_catalog')
    ->name('marketing.public.plan-catalog.preview');

Route::get('/public/contact-captcha', [PublicContactCaptchaController::class, 'show'])
    ->middleware('api.throttle:public_captcha')
    ->name('marketing.public.contact-captcha.show');

Route::post('/public/contact-enquiries', [PublicContactEnquiryController::class, 'store'])
    ->middleware('api.throttle:public_forms')
    ->name('marketing.public.contact-enquiries.store');

Route::post('/public/marketing-bff-throttle', [PublicMarketingBffThrottleController::class, 'consume'])
    ->name('marketing.public.bff-throttle');

Route::middleware(['auth:api', EnsureSuperAdmin::class])
    ->prefix('admin/marketing')
    ->name('marketing.admin.')
    ->group(function () {
        Route::get('/content/revisions', [AdminMarketingContentController::class, 'indexRevisions'])
            ->name('content.revisions.index');
        Route::get('/content/drafts/latest', [AdminMarketingContentController::class, 'showLatestDraft'])
            ->name('content.drafts.latest');
        Route::post('/content/drafts', [AdminMarketingContentController::class, 'storeDraft'])
            ->name('content.drafts.store');
        Route::get('/content/published', [AdminMarketingContentController::class, 'showPublished'])
            ->name('content.published.show');
        Route::post('/content/publish', [AdminMarketingContentController::class, 'publish'])
            ->name('content.publish');
        Route::post('/content/revisions/{revision}/publish', [AdminMarketingContentController::class, 'publishDraftRevision'])
            ->whereNumber('revision')
            ->name('content.revisions.publish');

        Route::get('/plan-catalog/status', [AdminMarketingPlanCatalogController::class, 'status'])
            ->name('plan-catalog.status');
        Route::get('/plan-catalog/revisions', [AdminMarketingPlanCatalogController::class, 'indexRevisions'])
            ->name('plan-catalog.revisions.index');
        Route::get('/plan-catalog/revisions/{revision}', [AdminMarketingPlanCatalogController::class, 'showRevision'])
            ->whereNumber('revision')
            ->name('plan-catalog.revisions.show');
        Route::post('/plan-catalog/previews', [AdminMarketingPlanCatalogController::class, 'generatePreview'])
            ->name('plan-catalog.previews.store');
        Route::post('/plan-catalog/revisions/{revision}/validate', [AdminMarketingPlanCatalogController::class, 'validateRevision'])
            ->whereNumber('revision')
            ->name('plan-catalog.revisions.validate');
        Route::get('/plan-catalog/revisions/{revision}/feature-visibility', [AdminMarketingPlanCatalogController::class, 'featureVisibility'])
            ->whereNumber('revision')
            ->name('plan-catalog.revisions.feature-visibility.show');
        Route::put('/plan-catalog/revisions/{revision}/feature-visibility', [AdminMarketingPlanCatalogController::class, 'updateFeatureVisibility'])
            ->whereNumber('revision')
            ->name('plan-catalog.revisions.feature-visibility.update');
        Route::post('/plan-catalog/revisions/{revision}/preview-token', [AdminMarketingPlanCatalogController::class, 'previewToken'])
            ->whereNumber('revision')
            ->name('plan-catalog.revisions.preview-token');
        Route::get('/plan-catalog/revisions/{revision}/publish-diff', [AdminMarketingPlanCatalogController::class, 'publishDiff'])
            ->whereNumber('revision')
            ->name('plan-catalog.revisions.publish-diff');
        Route::post('/plan-catalog/revisions/{revision}/publish', [AdminMarketingPlanCatalogController::class, 'publish'])
            ->whereNumber('revision')
            ->name('plan-catalog.revisions.publish');
        Route::post('/plan-catalog/revisions/{revision}/republish', [AdminMarketingPlanCatalogController::class, 'republish'])
            ->whereNumber('revision')
            ->name('plan-catalog.revisions.republish');
        Route::post('/plan-catalog/retry-revalidate', [AdminMarketingPlanCatalogController::class, 'retryRevalidate'])
            ->name('plan-catalog.retry-revalidate');

        Route::get('/seo-monitoring/snapshot', [AdminMarketingSeoMonitoringController::class, 'snapshot'])
            ->name('seo-monitoring.snapshot');
        Route::post('/seo-monitoring/scans', [AdminMarketingSeoMonitoringController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('seo-monitoring.scans.store');
    });

Route::middleware(['auth:api'])
    ->prefix('admin/web-settings')
    ->name('web-settings.admin.')
    ->group(function () {
        Route::get('/', [AdminWebSettingsController::class, 'show'])
            ->middleware('web_settings.permission:web_settings.view');
        Route::put('/', [AdminWebSettingsController::class, 'update'])
            ->middleware('web_settings.permission:web_settings.update');
        Route::post('/test-email', [AdminWebSettingsController::class, 'sendTestEmail'])
            ->middleware('web_settings.permission:web_settings.send_test_email');
    });

Route::middleware(['auth:api'])
    ->prefix('admin/contact-enquiries')
    ->name('contact-enquiries.admin.')
    ->group(function () {
        Route::get('/', [AdminContactEnquiryController::class, 'index'])
            ->middleware('web_settings.permission:contact_enquiries.view');
        Route::get('/{enquiry}', [AdminContactEnquiryController::class, 'show'])
            ->middleware('web_settings.permission:contact_enquiries.view')
            ->whereUuid('enquiry');
        Route::patch('/{enquiry}', [AdminContactEnquiryController::class, 'update'])
            ->middleware('web_settings.permission:contact_enquiries.update')
            ->whereUuid('enquiry');
        Route::post('/{enquiry}/retry-email', [AdminContactEnquiryController::class, 'retryEmail'])
            ->middleware('web_settings.permission:contact_enquiries.retry_email')
            ->whereUuid('enquiry');
        Route::delete('/{enquiry}', [AdminContactEnquiryController::class, 'destroy'])
            ->middleware('web_settings.permission:contact_enquiries.delete')
            ->whereUuid('enquiry');
    });
