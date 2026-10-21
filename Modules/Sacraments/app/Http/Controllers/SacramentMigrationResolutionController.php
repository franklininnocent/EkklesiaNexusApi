<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Http\Requests\ResolveSacramentMigrationRequest;
use Modules\Sacraments\Http\Resources\SacramentMigrationResolutionResource;
use Modules\Sacraments\Models\SacramentMigrationResolution;
use Modules\Sacraments\Services\Migration\SacramentMigrationReportService;
use Modules\Sacraments\Services\Migration\SacramentMigrationResolveService;
use Modules\Sacraments\Services\Migration\SacramentParticipantBackfillService;
use Modules\Tenants\Support\TenantContext;

class SacramentMigrationResolutionController extends Controller
{
    public function __construct(
        protected SacramentMigrationReportService $reportService,
        protected SacramentMigrationResolveService $resolveService,
        protected SacramentParticipantBackfillService $backfill
    ) {}

    public function report(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        return response()->json([
            'success' => true,
            'data' => $this->reportService->report($tenantId),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        $query = SacramentMigrationResolution::query()
            ->with(['legacySacrament', 'candidateMember'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if ($request->filled('resolution')) {
            $query->where('resolution', $request->string('resolution')->toString());
        }
        if ($request->filled('confidence')) {
            $query->where('confidence', $request->string('confidence')->toString());
        }
        if ($request->filled('q')) {
            $term = $request->string('q')->toString();
            $query->where(function ($inner) use ($term) {
                $inner->where('legacy_name', 'like', '%'.$term.'%')
                    ->orWhere('migration_key', 'like', '%'.$term.'%');
            });
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));
        $page = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'data' => SacramentMigrationResolutionResource::collection($page->items()),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $row = SacramentMigrationResolution::query()
                ->with(['legacySacrament', 'candidateMember'])
                ->where('tenant_id', $tenantId)
                ->find($id);

            if (! $row) {
                throw new SacramentBusinessRuleException(
                    'migration_resolution_not_found',
                    'Migration resolution not found.',
                    [],
                    404
                );
            }

            return response()->json([
                'success' => true,
                'data' => new SacramentMigrationResolutionResource($row),
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        }
    }

    public function resolve(ResolveSacramentMigrationRequest $request, int $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $row = $this->resolveService->resolve(
                $id,
                $tenantId,
                $request->validated(),
                $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'data' => new SacramentMigrationResolutionResource($row->load(['legacySacrament', 'candidateMember'])),
                'message' => 'Migration resolution updated',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Throwable $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Migration resolve failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve migration row',
            ], 500);
        }
    }

    public function backfill(Request $request): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $dryRun = (bool) $request->boolean('dry_run');
            $totals = $this->backfill->run($tenantId, $dryRun, 200);

            return response()->json([
                'success' => true,
                'data' => [
                    'totals' => $totals,
                    'report' => $this->reportService->report($tenantId),
                ],
                'message' => $dryRun ? 'Dry run complete' : 'Backfill complete',
            ]);
        } catch (\Throwable $e) {
            ApiErrorResponse::rethrowExpected($e);
            Log::error('Migration backfill failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to run backfill',
            ], 500);
        }
    }
}
