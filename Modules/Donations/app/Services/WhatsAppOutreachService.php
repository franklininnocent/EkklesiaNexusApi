<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\ChurchMoneyFormatter;

class WhatsAppOutreachService
{
    public function __construct(private readonly DonationNotificationService $notificationService) {}

    /**
     * @param  array<int, string>|null  $familyIds
     * @return array<string, mixed>
     */
    public function preview(int $tenantId, ?array $familyIds = null, int $limit = 20): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $targets = $this->resolveTargets($tenantId, $familyIds, $limit);

        return [
            'available' => count($targets) > 0,
            'tenant_name' => $tenant?->name,
            'eligible_count' => count($targets),
            'targets' => $targets,
            'template' => $this->defaultTemplate($tenant?->name ?? 'your church'),
        ];
    }

    /**
     * @param  array<int, string>  $familyIds
     * @return array<string, mixed>
     */
    public function queue(int $tenantId, array $familyIds, ?string $customMessage = null): array
    {
        $targets = $this->resolveTargets($tenantId, $familyIds, count($familyIds));
        $queued = [];

        foreach ($targets as $target) {
            if (! $target['phone']) {
                continue;
            }

            $message = $customMessage ?: $target['message'];
            $log = $this->notificationService->queue(
                $tenantId,
                'due.whatsapp_outreach',
                'whatsapp',
                $target['phone'],
                [
                    'message' => $message,
                    'whatsapp_url' => $target['whatsapp_url'],
                    'family_name' => $target['family_name'],
                    'overdue_amount' => $target['overdue_amount'],
                ],
                'family',
                $target['family_id']
            );

            $queued[] = [
                'notification_id' => $log->id,
                'family_id' => $target['family_id'],
                'family_name' => $target['family_name'],
                'phone' => $target['phone'],
                'whatsapp_url' => $target['whatsapp_url'],
            ];
        }

        return [
            'queued_count' => count($queued),
            'targets' => $queued,
        ];
    }

    /**
     * @param  array<int, string>|null  $familyIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveTargets(int $tenantId, ?array $familyIds, int $limit): array
    {
        $today = \Modules\Donations\Support\DonationBusinessDate::today($tenantId);
        $query = ContributionDue::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('due_date', '<', $today)
            ->with(['family:id,family_name,family_code,head_of_family']);
        \Modules\Donations\Support\ContributionBalance::scopeCollectable($query, $today);

        if ($familyIds) {
            $query->whereIn('family_id', $familyIds);
        }

        $dues = $query->get();
        $grouped = $dues->groupBy('family_id');

        $targets = [];
        foreach ($grouped as $familyId => $familyDues) {
            $family = $familyDues->first()?->family;
            if (! $family) {
                continue;
            }

            $phone = $this->resolveFamilyPhone((string) $familyId);
            $overdueAmount = round((float) $familyDues->sum(fn ($due) => ContributionBalance::outstandingForDue($due)), 2);
            $message = sprintf(
                'Dear %s, this is a gentle reminder from %s regarding outstanding contributions of %s. Please contact the parish office if you need assistance.',
                $family->head_of_family ?: $family->family_name,
                Tenant::query()->find($tenantId)?->name ?? 'your church',
                ChurchMoneyFormatter::formatForTenant($tenantId, $overdueAmount)
            );

            $targets[] = [
                'family_id' => $familyId,
                'family_name' => $family->family_name,
                'family_code' => $family->family_code,
                'head_of_family' => $family->head_of_family,
                'phone' => $phone,
                'overdue_amount' => $overdueAmount,
                'message' => $message,
                'whatsapp_url' => $phone ? $this->buildWhatsAppUrl($phone, $message) : null,
            ];
        }

        usort($targets, fn (array $a, array $b): int => $b['overdue_amount'] <=> $a['overdue_amount']);

        return array_slice($targets, 0, $limit);
    }

    private function resolveFamilyPhone(string $familyId): ?string
    {
        $member = FamilyMember::query()
            ->where('family_id', $familyId)
            ->where(function ($query): void {
                $query->where('is_primary_contact', true)
                    ->orWhere('relationship_to_head', 'self');
            })
            ->whereNotNull('phone')
            ->orderByDesc('is_primary_contact')
            ->first();

        if (! $member?->phone) {
            $member = FamilyMember::query()
                ->where('family_id', $familyId)
                ->whereNotNull('phone')
                ->first();
        }

        return $this->normalizePhone($member?->phone);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '91'.$digits;
        }

        return ltrim($digits, '0');
    }

    private function buildWhatsAppUrl(string $phone, string $message): string
    {
        return 'https://wa.me/'.$phone.'?text='.rawurlencode($message);
    }

    private function defaultTemplate(string $tenantName): string
    {
        return 'Dear {family_head}, this is a gentle reminder from '.$tenantName.' regarding outstanding contributions of {overdue_amount}. Please contact the parish office if you need assistance.';
    }
}
