<?php

namespace Modules\Marketing\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Marketing\Http\Requests\UpdateMarketingPlanFeatureVisibilityRequest;
use Modules\Marketing\Models\MarketingPlanCatalogRevision;
use Modules\Marketing\Services\MarketingPlanCatalogService;

class AdminMarketingPlanCatalogController extends Controller
{
    public function status(MarketingPlanCatalogService $catalog): JsonResponse
    {
        try {
            $data = $catalog->getStatusOverview();
        } catch (QueryException) {
            return response()->json([
                'success' => false,
                'message' => 'Marketing plan catalog tables are missing. Run php artisan migrate on the API server.',
                'code' => 'schema_missing',
            ], 503);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function indexRevisions(): JsonResponse
    {
        $rows = MarketingPlanCatalogRevision::query()
            ->orderByDesc('id')
            ->limit(40)
            ->get()
            ->map(static fn (MarketingPlanCatalogRevision $row) => [
                'id' => $row->id,
                'revision_number' => $row->revision_number,
                'status' => $row->status,
                'payload_hash' => $row->payload_hash,
                'source_fingerprint' => $row->source_fingerprint,
                'plan_count' => is_array($row->payload) ? count($row->payload) : 0,
                'generated_at' => $row->generated_at?->toIso8601String(),
                'published_at' => $row->published_at?->toIso8601String(),
                'notes' => $row->notes,
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function showRevision(int $revision): JsonResponse
    {
        $row = MarketingPlanCatalogRevision::query()->findOrFail($revision);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $row->id,
                'revision_number' => $row->revision_number,
                'status' => $row->status,
                'payload' => $row->payload,
                'payload_hash' => $row->payload_hash,
                'feature_visibility' => $row->feature_visibility,
                'source_refs' => $row->source_refs,
                'source_fingerprint' => $row->source_fingerprint,
                'validation_report' => $row->validation_report,
                'generated_at' => $row->generated_at?->toIso8601String(),
                'published_at' => $row->published_at?->toIso8601String(),
            ],
        ]);
    }

    public function generatePreview(MarketingPlanCatalogService $catalog): JsonResponse
    {
        try {
            $revision = $catalog->generatePreview(request()->user(), request()->input('notes'));
        } catch (QueryException $e) {
            Log::error('marketing.plan_catalog.preview_schema_error', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'The marketing plan catalog is not installed in the database yet. On the API server, run: php artisan migrate',
                'code' => 'schema_missing',
            ], 503);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'validation_failed',
            ], 422);
        } catch (\Throwable $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('marketing.plan_catalog.preview_failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Preview could not be generated. Check the API logs or try again.',
                'code' => 'server_error',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Preview revision generated.',
            'data' => [
                'revision_id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'payload_hash' => $revision->payload_hash,
                'validation_report' => $revision->validation_report,
            ],
        ], 201);
    }

    public function validateRevision(int $revision, MarketingPlanCatalogService $catalog): JsonResponse
    {
        $row = MarketingPlanCatalogRevision::query()->findOrFail($revision);
        $report = $catalog->validateRevision($row);

        return response()->json([
            'success' => $report['errors'] === [],
            'data' => $report,
        ], $report['errors'] === [] ? 200 : 422);
    }

    public function featureVisibility(int $revision, MarketingPlanCatalogService $catalog): JsonResponse
    {
        try {
            $data = $catalog->getFeatureVisibilityEditor($revision);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateFeatureVisibility(
        int $revision,
        UpdateMarketingPlanFeatureVisibilityRequest $request,
        MarketingPlanCatalogService $catalog,
    ): JsonResponse {
        try {
            $row = $catalog->updateFeatureVisibility(
                $request->user(),
                $revision,
                (string) $request->validated('payload_hash'),
                array_filter(
                    [
                        'hidden' => $request->validated('hidden'),
                        'hidden_plans' => $request->validated('hidden_plans'),
                    ],
                    static fn ($value, $key): bool => $key === 'hidden' || $request->exists($key),
                    ARRAY_FILTER_USE_BOTH,
                ),
            );
        } catch (InvalidArgumentException $e) {
            $conflict = str_contains(strtolower($e->getMessage()), 'changed')
                || str_contains(strtolower($e->getMessage()), 'refresh');

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $conflict ? 'stale_revision' : 'validation_failed',
            ], $conflict ? 409 : 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Feature visibility saved on this draft. The live website is unchanged.',
            'data' => $catalog->getFeatureVisibilityEditor((int) $row->id),
        ]);
    }

    public function previewToken(int $revision, MarketingPlanCatalogService $catalog): JsonResponse
    {
        try {
            $token = $catalog->issuePreviewToken(request()->user(), $revision);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $siteUrl = rtrim((string) config('marketing.plan_catalog.site_url', ''), '/');

        return response()->json([
            'success' => true,
            'data' => $token + [
                'preview_url' => $siteUrl !== '' ? $siteUrl.$token['preview_path'] : null,
            ],
        ]);
    }

    public function publish(int $revision, MarketingPlanCatalogService $catalog): JsonResponse
    {
        $payloadHash = (string) request()->input('payload_hash', '');
        if ($payloadHash === '') {
            return response()->json(['success' => false, 'message' => 'payload_hash is required.'], 422);
        }

        if (! request()->boolean('confirm')) {
            return response()->json(['success' => false, 'message' => 'Explicit confirmation is required.'], 422);
        }

        try {
            $published = $catalog->publishRevision(request()->user(), $revision, $payloadHash);
            $catalog->afterPublishCommit($published);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        $plans = is_array($published->payload) ? $published->payload : [];

        return response()->json([
            'success' => true,
            'message' => 'Marketing plan catalog published.',
            'data' => [
                'revision_id' => $published->id,
                'revision_number' => $published->revision_number,
                'published_at' => $published->published_at?->toIso8601String(),
                'plan_codes' => array_column($plans, 'code'),
                'diff' => $catalog->diffAgainstPublished($plans),
            ],
        ], 201);
    }

    public function publishDiff(int $revision, MarketingPlanCatalogService $catalog): JsonResponse
    {
        $row = MarketingPlanCatalogRevision::query()->findOrFail($revision);
        return response()->json([
            'success' => true,
            'data' => $catalog->publishReview($row),
        ]);
    }

    public function republish(int $revision, MarketingPlanCatalogService $catalog): JsonResponse
    {
        if (! request()->boolean('confirm')) {
            return response()->json(['success' => false, 'message' => 'Explicit confirmation is required.'], 422);
        }

        try {
            $published = $catalog->republishHistorical(request()->user(), $revision);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Historical revision republished.',
            'data' => [
                'revision_id' => $published->id,
                'revision_number' => $published->revision_number,
            ],
        ], 201);
    }

    public function retryRevalidate(MarketingPlanCatalogService $catalog): JsonResponse
    {
        $result = $catalog->retryRevalidate();

        return response()->json([
            'success' => $result['status'] !== 'failed',
            'data' => $result,
        ]);
    }
}
