<?php

namespace Modules\Donations\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\PaymentAllocation;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Family\Models\Family;

/**
 * Optional parish-dashboard scope: collections and installments for one donation project.
 */
final class DashboardProjectFilter
{
    private function __construct(
        public readonly bool $isActive,
        public readonly ?string $projectId,
        public readonly ?string $projectName,
    ) {}

    public static function none(): self
    {
        return new self(false, null, null);
    }

    public static function resolve(int $tenantId, mixed $projectId): self
    {
        if (! is_string($projectId) || $projectId === '') {
            return self::none();
        }

        if (! Str::isUuid($projectId)) {
            throw new InvalidArgumentException('The selected project is not available for this parish.');
        }

        $row = DB::table('donation_projects')
            ->where('id', $projectId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select('id', 'name')
            ->first();

        if ($row === null) {
            throw new InvalidArgumentException('The selected project is not available for this parish.');
        }

        return new self(true, (string) $row->id, (string) $row->name);
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function appliedPayload(): ?array
    {
        if (! $this->isActive || $this->projectId === null) {
            return null;
        }

        return [
            'id' => $this->projectId,
            'name' => $this->projectName ?? 'Project',
        ];
    }

    /**
     * @param  Builder<DonationPayment>  $query
     */
    public function applyToDonationPaymentQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive || $this->projectId === null) {
            return;
        }

        $projectId = $this->projectId;
        $query->whereExists(function ($sub) use ($tenantId, $projectId): void {
            $sub->from('payment_allocations')
                ->whereColumn('payment_allocations.payment_id', 'donation_payments.id')
                ->where('payment_allocations.tenant_id', $tenantId)
                ->whereNull('payment_allocations.deleted_at')
                ->where(function ($inner) use ($projectId): void {
                    $inner->where(function ($direct) use ($projectId): void {
                        $direct->where('payment_allocations.allocatable_type', 'project')
                            ->where('payment_allocations.allocatable_id', $projectId);
                    })->orWhere(function ($installment) use ($projectId): void {
                        $installment->where('payment_allocations.allocatable_type', 'project_installment')
                            ->whereIn('payment_allocations.allocatable_id', function ($ids) use ($projectId): void {
                                $ids->from('project_installment_dues')
                                    ->select('id')
                                    ->where('project_id', $projectId);
                            });
                    });
                });
        });
    }

    /**
     * @param  Builder<PaymentAllocation>  $query
     */
    public function applyToPaymentAllocationQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive || $this->projectId === null) {
            return;
        }

        $projectId = $this->projectId;
        $query->where(function ($inner) use ($projectId): void {
            $inner->where(function ($direct) use ($projectId): void {
                $direct->where('allocatable_type', 'project')
                    ->where('allocatable_id', $projectId);
            })->orWhere(function ($installment) use ($projectId): void {
                $installment->where('allocatable_type', 'project_installment')
                    ->whereIn('allocatable_id', function ($ids) use ($projectId): void {
                        $ids->from('project_installment_dues')
                            ->select('id')
                            ->where('project_id', $projectId);
                    });
            });
        });
    }

    /**
     * @param  Builder<Family>  $query
     */
    public function applyToFamilyQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive || $this->projectId === null) {
            return;
        }

        $projectId = $this->projectId;
        $query->where('tenant_id', $tenantId)->where(function ($scoped) use ($tenantId, $projectId): void {
            $scoped->whereExists(function ($sub) use ($tenantId, $projectId): void {
                $sub->from('project_family_assignments')
                    ->whereColumn('project_family_assignments.family_id', 'families.id')
                    ->where('project_family_assignments.tenant_id', $tenantId)
                    ->where('project_family_assignments.project_id', $projectId)
                    ->whereNull('project_family_assignments.deleted_at');
            })->orWhereExists(function ($sub) use ($tenantId, $projectId): void {
                $sub->from('project_installment_dues')
                    ->whereColumn('project_installment_dues.family_id', 'families.id')
                    ->where('project_installment_dues.tenant_id', $tenantId)
                    ->where('project_installment_dues.project_id', $projectId)
                    ->whereNull('project_installment_dues.deleted_at');
            });
        });
    }

    /**
     * @param  Builder<ContributionDue>  $query
     */
    public function applyToContributionDueQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive) {
            return;
        }

        $query->whereRaw('1 = 0');
    }

    /**
     * @param  Builder<ProjectInstallmentDue>  $query
     */
    public function applyToProjectInstallmentDueQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive || $this->projectId === null) {
            return;
        }

        $query->where('tenant_id', $tenantId)->where('project_id', $this->projectId);
    }
}
