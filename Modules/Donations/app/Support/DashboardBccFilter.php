<?php

namespace Modules\Donations\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Family\Models\Family;

/**
 * Optional parish-dashboard scope: families (and their payments/dues) in one BCC.
 */
final class DashboardBccFilter
{
    public const UNASSIGNED = 'unassigned';

    private function __construct(
        public readonly bool $isActive,
        public readonly ?string $bccId,
        public readonly bool $unassignedOnly,
        public readonly ?string $bccName,
    ) {}

    public static function none(): self
    {
        return new self(false, null, false, null);
    }

    public static function resolve(int $tenantId, mixed $bccId): self
    {
        if (! is_string($bccId) || $bccId === '') {
            return self::none();
        }

        if ($bccId === self::UNASSIGNED) {
            return new self(true, null, true, 'Unassigned Area');
        }

        if (! Str::isUuid($bccId)) {
            throw new InvalidArgumentException('The selected BCC is not available for this parish.');
        }

        $row = DB::table('bccs')
            ->where('id', $bccId)
            ->where('tenant_id', $tenantId)
            ->select('id', 'name')
            ->first();

        if ($row === null) {
            throw new InvalidArgumentException('The selected BCC is not available for this parish.');
        }

        return new self(true, (string) $row->id, false, (string) $row->name);
    }

    /**
     * @return array{id: string|null, name: string, unassigned: bool}|null
     */
    public function appliedPayload(): ?array
    {
        if (! $this->isActive) {
            return null;
        }

        return [
            'id' => $this->unassignedOnly ? self::UNASSIGNED : $this->bccId,
            'name' => $this->bccName ?? 'Unassigned Area',
            'unassigned' => $this->unassignedOnly,
        ];
    }

    /**
     * @param  Builder<Family>  $query
     */
    public function applyToFamilyQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive) {
            return;
        }

        $query->where('tenant_id', $tenantId);

        if ($this->unassignedOnly) {
            $query->whereNull('bcc_id');

            return;
        }

        $query->where('bcc_id', $this->bccId);
    }

    /**
     * @param  Builder<DonationPayment>  $query
     */
    public function applyToDonationPaymentQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive) {
            return;
        }

        $query->whereNotNull('family_id')->whereIn('family_id', $this->familyIdSubquery($tenantId));
    }

    /**
     * @param  Builder<ContributionDue>  $query
     */
    public function applyToContributionDueQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive) {
            return;
        }

        $query->whereIn('family_id', $this->familyIdSubquery($tenantId));
    }

    /**
     * @param  Builder<ProjectInstallmentDue>  $query
     */
    public function applyToProjectInstallmentDueQuery(Builder $query, int $tenantId): void
    {
        if (! $this->isActive) {
            return;
        }

        $query->whereIn('family_id', $this->familyIdSubquery($tenantId));
    }

    public function familyBelongsToFilter(Family $family): bool
    {
        if (! $this->isActive) {
            return true;
        }

        if ($this->unassignedOnly) {
            return $family->bcc_id === null;
        }

        return (string) $family->bcc_id === (string) $this->bccId;
    }

    /**
     * @return \Closure(\Illuminate\Database\Query\Builder): void
     */
    private function familyIdSubquery(int $tenantId): \Closure
    {
        return function ($sub) use ($tenantId): void {
            $sub->from('families')->select('id')->where('tenant_id', $tenantId);
            if ($this->unassignedOnly) {
                $sub->whereNull('bcc_id');
            } elseif ($this->bccId !== null) {
                $sub->where('bcc_id', $this->bccId);
            }
        };
    }
}
