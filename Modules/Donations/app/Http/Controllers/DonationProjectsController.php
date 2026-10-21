<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Donations\Exceptions\ScheduleGenerationBusyException;
use Modules\Donations\Http\Requests\GenerateProjectInstallmentsRequest;
use Modules\Donations\Http\Requests\IndexProjectFamilyProgressRequest;
use Modules\Donations\Http\Requests\StoreProjectRequest;
use Modules\Donations\Http\Requests\UpdateProjectRequest;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Services\DonationProjectService;
use Modules\Donations\Services\ProjectInstallmentDueService;
use Modules\Tenants\Support\TenantContext;

class DonationProjectsController extends Controller
{
    public function __construct(
        private readonly DonationProjectService $projectService,
        private readonly ProjectInstallmentDueService $installmentService
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        $collection = DonationProject::forTenant($tenantId)
            ->where('entity_kind', 'project')
            ->with('fund')
            ->withCount(['assignments', 'installmentDues'])
            ->orderByDesc('created_at')
            ->get();

        $summaries = $this->projectService->summarizeProjectsForList($tenantId, $collection);
        $enrolled = [];
        foreach ($summaries as $projectId => $summary) {
            $enrolled[$projectId] = (int) ($summary['families_enrolled'] ?? 0);
        }
        $schedules = $this->installmentService->summarizeSchedules($tenantId, $collection->pluck('id')->all(), $enrolled);

        $projects = $collection->map(function (DonationProject $project) use ($summaries, $schedules): array {
            return array_merge($project->toArray(), $summaries[$project->id] ?? [], [
                'installment_schedule' => $schedules[$project->id] ?? null,
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $projects,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        $project = DonationProject::forTenant($tenantId)
            ->where('entity_kind', 'project')
            ->with(['fund', 'assignments.family'])
            ->withCount(['assignments', 'installmentDues'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $project,
        ]);
    }

    public function dashboard(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $project = DonationProject::forTenant($tenantId)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->projectService->getDashboard($tenantId, $project),
        ]);
    }

    public function familyProgress(string $id, IndexProjectFamilyProgressRequest $request): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);

        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $project = DonationProject::forTenant($tenantId)->findOrFail($id);

        $result = $this->projectService->paginateFamilyProgress(
            $tenantId,
            $project,
            $request->bccFilter($tenantId),
            $request->listOptions()
        );

        return response()->json([
            'success' => true,
            'data' => $result['paginator'],
            'meta' => [
                'filter_options' => [
                    'bccs' => $result['bcc_options'],
                ],
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();

        $project = $this->projectService->create($tenantId, $userId, $request->validated());

        if ($project->auto_generate_installments && $project->status === 'active') {
            $this->installmentService->generateForProject($tenantId, $userId, $project);
        }

        return response()->json([
            'success' => true,
            'message' => 'Project created successfully.',
            'data' => $project->fresh(['fund', 'assignments.family']),
        ], 201);
    }

    public function update(string $id, UpdateProjectRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $project = DonationProject::forTenant($tenantId)
            ->where('entity_kind', 'project')
            ->findOrFail($id);

        $project = $this->projectService->update($tenantId, $userId, $project, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Project updated successfully.',
            'data' => $project,
        ]);
    }

    public function installmentSchedule(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $project = DonationProject::forTenant($tenantId)->findOrFail($id);
        $enrolled = $this->projectService->getListSummary($tenantId, $project)['families_enrolled'] ?? 0;

        $schedule = $this->installmentService->summarizeSchedules($tenantId, [$project->id], [
            $project->id => (int) $enrolled,
        ]);

        return response()->json([
            'success' => true,
            'data' => $schedule[$project->id],
        ]);
    }

    public function generateInstallments(string $id, GenerateProjectInstallmentsRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $project = DonationProject::forTenant($tenantId)
            ->where('entity_kind', 'project')
            ->findOrFail($id);
        $payload = $request->validated();

        try {
            $summary = $this->installmentService->generateForProject(
                $tenantId,
                $userId,
                $project,
                $payload['family_ids'] ?? null,
                $payload['mode'] ?? 'generate',
                $payload['reason'] ?? null
            );
        } catch (ScheduleGenerationBusyException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 409);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $enrolled = (int) ($this->projectService->getListSummary($tenantId, $project->fresh())['families_enrolled'] ?? 0);
        $schedule = $this->installmentService->summarizeSchedules($tenantId, [$project->id], [
            $project->id => $enrolled,
        ]);
        $summary['schedule'] = $schedule[$project->id];

        $blocked = ($summary['outcome'] ?? null) === 'blocked';

        return response()->json([
            'success' => ! $blocked,
            'message' => $this->generationMessage($summary),
            'data' => $summary,
        ], $blocked ? 409 : 200);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function generationMessage(array $summary): string
    {
        $locked = (int) ($summary['families_locked'] ?? 0);
        $lockedNote = $locked > 0
            ? " {$locked} ".($locked === 1 ? 'family has' : 'families have').' payments, so those installments were left unchanged.'
            : '';

        return match ($summary['outcome'] ?? 'empty') {
            'generated' => "Generated {$summary['created']} installments for {$summary['families']} families.",
            'already_generated' => 'Installments are already generated. View them to collect payments, or regenerate unpaid installments if the project settings changed.',
            'regenerated' => "Updated the unpaid installment schedule.{$lockedNote}",
            'unchanged' => 'Unpaid installments already match the current project settings.'.$lockedNote,
            'blocked' => 'Payments are recorded for every family on this schedule. Paid and partially paid installments were not changed.',
            default => 'No installments were generated. Enroll active families and set a family target first.',
        };
    }
}
