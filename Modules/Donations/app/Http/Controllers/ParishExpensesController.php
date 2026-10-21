<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\StoreParishExpenseRequest;
use Modules\Donations\Models\ParishExpense;
use Modules\Donations\Services\DonationAuditService;
use Modules\Tenants\Services\ChurchCurrencyResolver;
use Modules\Tenants\Support\TenantContext;

class ParishExpensesController extends Controller
{
    public function __construct(private readonly DonationAuditService $auditService) {}

    public function index(): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $expenses = ParishExpense::forTenant($tenantId)
            ->orderByDesc('expense_date')
            ->limit(100)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $expenses,
        ]);
    }

    public function store(StoreParishExpenseRequest $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $payload = $request->validated();
        $payload['tenant_id'] = $tenantId;
        $payload['recorded_by'] = $userId;
        $payload['created_by'] = $userId;
        $payload['updated_by'] = $userId;
        $currency = app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId);
        if (! is_string($currency) || $currency === '') {
            return response()->json([
                'success' => false,
                'message' => 'Parish currency is not configured.',
            ], 422);
        }
        $payload['currency'] = $currency;
        $payload['method'] = $payload['method'] ?? 'cash';
        $payload['status'] = $payload['status'] ?? 'recorded';

        $expense = ParishExpense::create($payload);
        $this->auditService->log($tenantId, 'expense.recorded', 'parish_expense', $expense->id, null, $expense->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Parish disbursement recorded.',
            'data' => $expense,
        ], 201);
    }
}
