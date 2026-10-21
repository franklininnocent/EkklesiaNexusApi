<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\IndexDonationNotificationsRequest;
use Modules\Donations\Models\DonationNotificationLog;
use Modules\Donations\Services\DonationNotificationService;

class DonationNotificationsController extends Controller
{
    public function __construct(private readonly DonationNotificationService $notificationService)
    {
    }

    public function index(IndexDonationNotificationsRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $table = (new DonationNotificationLog)->getTable();
        $query = DonationNotificationLog::forTenant($tenantId);

        if ($request->filled('status')) {
            $query->where("{$table}.status", $request->string('status'));
        }

        $query->orderBy("{$table}.{$request->sortColumn()}", $request->sortDirection());

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->input('per_page', 20)),
        ]);
    }

    public function queueReminder(Request $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $targetType = $request->input('target_type');
        $targetId = $request->input('target_id');
        if ($targetType === 'due' && is_string($targetId) && $targetId !== '') {
            $due = \Modules\Donations\Models\ContributionDue::forTenant($tenantId)->find($targetId);
            if (! $due || ! \Modules\Donations\Support\ContributionBalance::isCollectableOverdue($due, \Modules\Donations\Support\DonationBusinessDate::today($tenantId))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reminders are sent only for collectable overdue dues.',
                ], 422);
            }
        }

        $log = $this->notificationService->queue(
            $tenantId,
            $request->input('notification_type', 'due.reminder'),
            $request->input('channel', 'in_app'),
            $request->input('recipient'),
            $request->input('payload', []),
            $request->input('target_type'),
            $request->input('target_id')
        );

        return response()->json([
            'success' => true,
            'message' => 'Reminder queued successfully.',
            'data' => $log,
        ], 201);
    }
}
