<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Collection;
use Modules\Donations\Models\DonationAuditLog;
use Modules\Tenants\Support\ChurchMoneyFormatter;
use Modules\Tenants\Support\TenantFacingAuditActor;

class ParishActivityFeedPresenter
{
    /**
     * @param  Collection<int, DonationAuditLog>  $logs
     * @param  array<int, string>  $actorNames
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(Collection $logs, array $actorNames = []): array
    {
        return $logs->map(fn (DonationAuditLog $log) => $this->present($log, $actorNames))->all();
    }

    /**
     * @param  array<int, string>  $actorNames
     * @return array<string, mixed>
     */
    public function present(DonationAuditLog $log, array $actorNames = []): array
    {
        $event = (string) ($log->event ?? '');
        $targetType = (string) ($log->target_type ?? '');
        $newValues = is_array($log->new_values) ? $log->new_values : [];
        $oldValues = is_array($log->old_values) ? $log->old_values : [];
        $metadata = is_array($log->metadata) ? $log->metadata : [];
        $subjectName = $this->resolveSubjectName($event, $targetType, $newValues, $oldValues, $metadata);
        $copy = $this->resolveCopy($event, $subjectName, $newValues, $oldValues, $metadata, (int) $log->tenant_id);
        $action = $this->resolveAction($event, $targetType, $log->target_id, $newValues, $oldValues);

        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'category' => $copy['category'],
            'icon' => $copy['icon'],
            'title' => $copy['title'],
            'description' => $copy['description'],
            'subject_name' => $subjectName,
            'actor_name' => TenantFacingAuditActor::displayName(
                $this->resolveActorName($log->actor_user_id, $actorNames),
                $log->support_session_id
            ),
            'amount' => $this->resolveAmount($event, $newValues, $oldValues, $metadata),
            'currency' => $newValues['currency'] ?? $newValues['default_currency'] ?? null,
            'action_label' => $action['label'],
            'action_path' => $action['path'],
            'requires_attention' => $copy['requires_attention'],
        ];
    }

    /**
     * @return array{label: ?string, path: ?string}
     */
    private function resolveAction(string $event, string $targetType, ?string $targetId, array $newValues, array $oldValues): array
    {
        $subjectId = $newValues['id'] ?? $oldValues['id'] ?? $targetId;

        return match ($event) {
            'donor.created', 'donor.updated' => ['label' => 'View donors', 'path' => '/donations/donors'],
            'category.created', 'category.updated', 'category.deleted', 'category.defaults_seeded' => ['label' => 'View categories', 'path' => '/donations/categories'],
            'plan.created', 'plan.updated' => ['label' => 'View plans', 'path' => '/donations/plans'],
            'fund.created' => ['label' => 'View settings', 'path' => '/donations/settings'],
            'settings.updated' => ['label' => 'View settings', 'path' => '/donations/settings'],
            'donation.created', 'donation.updated' => ['label' => 'View register', 'path' => '/donations/register'],
            'payment.created', 'payment.reversed' => ['label' => 'View payments', 'path' => '/donations/payments'],
            'refund.requested' => ['label' => 'View payments', 'path' => '/donations/payments'],
            'project.created', 'project.updated', 'project.installments_generated' => $this->projectAction($newValues, $oldValues),
            'project_installment.waived', 'project_installment.cancelled' => ['label' => 'View installments', 'path' => '/donations/project-installments'],
            'due.created', 'due.waived', 'due.cancelled', 'due.bulk_generated' => ['label' => 'View dues', 'path' => '/donations/dues'],
            'recurring.created', 'recurring.updated', 'recurring.executed', 'recurring.execution_failed' => ['label' => 'View recurring gifts', 'path' => '/donations/recurring'],
            'batch.created' => ['label' => 'View payments', 'path' => '/donations/payments'],
            'report.exported' => ['label' => 'View reports', 'path' => '/donations/reports'],
            default => $this->fallbackAction($targetType, $subjectId),
        };
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $oldValues
     * @return array{label: string, path: string}
     */
    private function projectAction(array $newValues, array $oldValues): array
    {
        if ($this->isCampaign($newValues, $oldValues)) {
            return ['label' => 'View campaigns', 'path' => '/donations/campaigns'];
        }

        return ['label' => 'View projects', 'path' => '/donations/projects'];
    }

    /**
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $oldValues
     */
    private function isCampaign(array $newValues, array $oldValues): bool
    {
        $kind = (string) ($newValues['entity_kind'] ?? $oldValues['entity_kind'] ?? '');

        return $kind === 'campaign';
    }

    /**
     * @return array{label: ?string, path: ?string}
     */
    private function fallbackAction(string $targetType, ?string $subjectId): array
    {
        return match ($targetType) {
            'donor' => ['label' => 'View donors', 'path' => '/donations/donors'],
            'donation_category' => ['label' => 'View categories', 'path' => '/donations/categories'],
            'plan' => ['label' => 'View plans', 'path' => '/donations/plans'],
            'fund', 'donation_settings' => ['label' => 'View settings', 'path' => '/donations/settings'],
            'donation' => ['label' => 'View register', 'path' => '/donations/register'],
            'payment', 'payment_batch', 'refund' => ['label' => 'View payments', 'path' => '/donations/payments'],
            'project' => ['label' => 'View projects', 'path' => '/donations/projects'],
            'due' => ['label' => 'View dues', 'path' => '/donations/dues'],
            'recurring_schedule' => ['label' => 'View recurring gifts', 'path' => '/donations/recurring'],
            default => ['label' => null, 'path' => null],
        };
    }

    /**
     * @return array{category: string, icon: string, title: string, description: string, requires_attention: bool}
     */
    private function resolveCopy(string $event, ?string $subjectName, array $newValues, array $oldValues, array $metadata, int $tenantId): array
    {
        $name = $subjectName ?: 'this item';

        return match ($event) {
            'donor.created' => [
                'category' => 'donors',
                'icon' => 'donor',
                'title' => 'New Donor Added',
                'description' => "{$name} was added as a donor.",
                'requires_attention' => false,
            ],
            'donor.updated' => [
                'category' => 'donors',
                'icon' => 'donor',
                'title' => 'Donor Updated',
                'description' => "{$name} donor details were updated.",
                'requires_attention' => false,
            ],
            'category.created' => [
                'category' => 'categories',
                'icon' => 'category',
                'title' => 'Offering Category Created',
                'description' => "{$name} category was created.",
                'requires_attention' => false,
            ],
            'category.updated' => [
                'category' => 'categories',
                'icon' => 'category',
                'title' => 'Offering Category Updated',
                'description' => "{$name} category was updated.",
                'requires_attention' => false,
            ],
            'category.deleted' => [
                'category' => 'categories',
                'icon' => 'category',
                'title' => 'Offering Category Removed',
                'description' => "{$name} category was removed.",
                'requires_attention' => false,
            ],
            'category.defaults_seeded' => [
                'category' => 'categories',
                'icon' => 'category',
                'title' => 'Default Categories Added',
                'description' => $this->seededDescription($newValues),
                'requires_attention' => false,
            ],
            'plan.created' => [
                'category' => 'plans',
                'icon' => 'plan',
                'title' => 'New Contribution Plan Created',
                'description' => "{$name} was created.",
                'requires_attention' => false,
            ],
            'plan.updated' => [
                'category' => 'plans',
                'icon' => 'plan',
                'title' => 'Contribution Plan Updated',
                'description' => "{$name} was updated.",
                'requires_attention' => false,
            ],
            'fund.created' => [
                'category' => 'settings',
                'icon' => 'fund',
                'title' => 'Collection Purpose Created',
                'description' => "{$name} was added as a collection purpose.",
                'requires_attention' => false,
            ],
            'settings.updated' => [
                'category' => 'settings',
                'icon' => 'settings',
                'title' => 'Contribution Settings Updated',
                'description' => 'Contribution settings were updated.',
                'requires_attention' => false,
            ],
            'donation.created' => [
                'category' => 'offerings',
                'icon' => 'offering',
                'title' => 'Offering Recorded',
                'description' => $this->donationDescription($name, $newValues, $tenantId),
                'requires_attention' => false,
            ],
            'donation.updated' => [
                'category' => 'offerings',
                'icon' => 'offering',
                'title' => 'Offering Updated',
                'description' => "{$name} offering details were updated.",
                'requires_attention' => false,
            ],
            'payment.created' => [
                'category' => 'payments',
                'icon' => 'payment',
                'title' => 'Payment Collected',
                'description' => $this->paymentDescription($name, $newValues, false, $tenantId),
                'requires_attention' => false,
            ],
            'payment.reversed' => [
                'category' => 'payments',
                'icon' => 'payment',
                'title' => 'Payment Reversed',
                'description' => $this->paymentDescription($name, $newValues, true, $tenantId),
                'requires_attention' => true,
            ],
            'refund.requested' => [
                'category' => 'payments',
                'icon' => 'payment',
                'title' => 'Refund Requested',
                'description' => $this->refundDescription($newValues, $tenantId),
                'requires_attention' => true,
            ],
            'project.created' => [
                'category' => 'projects',
                'icon' => 'project',
                'title' => $this->isCampaign($newValues, $oldValues) ? 'Campaign Created' : 'Project Created',
                'description' => $this->isCampaign($newValues, $oldValues)
                    ? "{$name} campaign was created. It is a parish target, not a family bill."
                    : "{$name} project was created.",
                'requires_attention' => false,
            ],
            'project.updated' => [
                'category' => 'projects',
                'icon' => 'project',
                'title' => $this->isCampaign($newValues, $oldValues) ? 'Campaign Updated' : 'Project Updated',
                'description' => $this->isCampaign($newValues, $oldValues)
                    ? "{$name} campaign was updated."
                    : "{$name} project was updated.",
                'requires_attention' => false,
            ],
            'project.installments_generated' => [
                'category' => 'projects',
                'icon' => 'project',
                'title' => 'Project Installments Generated',
                'description' => "Installment schedule was generated for {$name}.",
                'requires_attention' => false,
            ],
            'project_installment.waived' => [
                'category' => 'projects',
                'icon' => 'project',
                'title' => 'Project Installment Waived',
                'description' => 'A project installment was waived.',
                'requires_attention' => false,
            ],
            'project_installment.cancelled' => [
                'category' => 'projects',
                'icon' => 'project',
                'title' => 'Project Installment Cancelled',
                'description' => 'A project installment was cancelled.',
                'requires_attention' => false,
            ],
            'due.created' => [
                'category' => 'plans',
                'icon' => 'plan',
                'title' => 'Contribution Due Created',
                'description' => 'A new contribution due was created.',
                'requires_attention' => false,
            ],
            'due.waived' => [
                'category' => 'plans',
                'icon' => 'plan',
                'title' => 'Contribution Due Waived',
                'description' => 'A contribution due was waived.',
                'requires_attention' => false,
            ],
            'due.cancelled' => [
                'category' => 'plans',
                'icon' => 'plan',
                'title' => 'Contribution Due Cancelled',
                'description' => 'A contribution due was cancelled.',
                'requires_attention' => false,
            ],
            'due.bulk_generated' => [
                'category' => 'plans',
                'icon' => 'plan',
                'title' => 'Contribution Dues Generated',
                'description' => $this->bulkDueDescription($newValues),
                'requires_attention' => false,
            ],
            'recurring.created' => [
                'category' => 'recurring',
                'icon' => 'recurring',
                'title' => 'Recurring Gift Scheduled',
                'description' => 'A recurring gift schedule was created.',
                'requires_attention' => false,
            ],
            'recurring.updated' => [
                'category' => 'recurring',
                'icon' => 'recurring',
                'title' => 'Recurring Gift Updated',
                'description' => 'A recurring gift schedule was updated.',
                'requires_attention' => false,
            ],
            'recurring.executed' => [
                'category' => 'recurring',
                'icon' => 'recurring',
                'title' => 'Recurring Gift Processed',
                'description' => 'A scheduled recurring gift was processed.',
                'requires_attention' => false,
            ],
            'recurring.execution_failed' => [
                'category' => 'recurring',
                'icon' => 'recurring',
                'title' => 'Recurring Gift Failed',
                'description' => 'A scheduled recurring gift could not be processed.',
                'requires_attention' => true,
            ],
            'batch.created' => [
                'category' => 'payments',
                'icon' => 'payment',
                'title' => 'Payment Batch Created',
                'description' => $this->batchDescription($newValues),
                'requires_attention' => false,
            ],
            'report.exported' => [
                'category' => 'reports',
                'icon' => 'report',
                'title' => 'Report Exported',
                'description' => $this->reportDescription($newValues),
                'requires_attention' => false,
            ],
            default => [
                'category' => 'general',
                'icon' => 'activity',
                'title' => $this->fallbackTitle($event),
                'description' => $subjectName
                    ? "{$subjectName} was updated."
                    : 'A stewardship record was updated.',
                'requires_attention' => false,
            ],
        };
    }

    private function resolveSubjectName(
        string $event,
        string $targetType,
        array $newValues,
        array $oldValues,
        array $metadata
    ): ?string {
        if ($event === 'category.defaults_seeded') {
            return null;
        }

        $candidates = [
            $newValues['name'] ?? null,
            $newValues['title'] ?? null,
            $newValues['payer_name'] ?? null,
            $newValues['plan_name'] ?? null,
            $newValues['project_name'] ?? null,
            $newValues['category_name'] ?? null,
            $newValues['report_type'] ?? null,
            $oldValues['name'] ?? null,
            $oldValues['title'] ?? null,
            $oldValues['payer_name'] ?? null,
            $metadata['name'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return match ($targetType) {
            'donation_settings' => 'Contribution settings',
            default => null,
        };
    }

    private function resolveAmount(string $event, array $newValues, array $oldValues, array $metadata): ?float
    {
        $keys = ['amount', 'default_amount', 'target_amount', 'collected_amount', 'amount_due'];

        foreach ([$newValues, $oldValues, $metadata] as $source) {
            foreach ($keys as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) {
                    return round((float) $source[$key], 2);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $actorNames
     */
    private function resolveActorName(?int $actorUserId, array $actorNames): string
    {
        if ($actorUserId && isset($actorNames[$actorUserId])) {
            return $actorNames[$actorUserId];
        }

        return 'Parish team member';
    }

    private function seededDescription(array $newValues): string
    {
        $count = (int) ($newValues['count'] ?? 0);

        return $count > 0
            ? "Default offering categories were added ({$count} categories)."
            : 'Default offering categories were added.';
    }

    private function donationDescription(string $name, array $newValues, int $tenantId): string
    {
        $amount = isset($newValues['collected_amount']) && is_numeric($newValues['collected_amount'])
            ? ChurchMoneyFormatter::formatForTenant($tenantId, (float) $newValues['collected_amount'])
            : null;

        return $amount
            ? "{$name} offering was recorded for {$amount}."
            : "{$name} offering was recorded.";
    }

    private function paymentDescription(string $name, array $newValues, bool $reversed = false, int $tenantId = 0): string
    {
        $amount = isset($newValues['amount']) && is_numeric($newValues['amount'])
            ? ChurchMoneyFormatter::formatForTenant($tenantId, (float) $newValues['amount'])
            : null;
        $verb = $reversed ? 'was reversed for' : 'was collected from';

        if ($amount) {
            return "A payment {$verb} {$name} for {$amount}.";
        }

        return $reversed
            ? "A payment for {$name} was reversed."
            : "A payment was collected from {$name}.";
    }

    private function refundDescription(array $newValues, int $tenantId): string
    {
        $amount = isset($newValues['amount']) && is_numeric($newValues['amount'])
            ? ChurchMoneyFormatter::formatForTenant($tenantId, (float) $newValues['amount'])
            : null;

        return $amount
            ? "A refund of {$amount} was requested and needs review."
            : 'A refund was requested and needs review.';
    }

    private function bulkDueDescription(array $newValues): string
    {
        $count = (int) ($newValues['generated_count'] ?? $newValues['count'] ?? 0);

        return $count > 0
            ? "Contribution dues were generated for {$count} families."
            : 'Contribution dues were generated.';
    }

    private function batchDescription(array $newValues): string
    {
        $count = (int) ($newValues['payments_count'] ?? 0);

        return $count > 0
            ? "A payment batch with {$count} payments was created."
            : 'A payment batch was created.';
    }

    private function reportDescription(array $newValues): string
    {
        $type = $newValues['report_type'] ?? null;

        return is_string($type) && $type !== ''
            ? ucfirst(str_replace('_', ' ', $type)).' report was exported.'
            : 'A stewardship report was exported.';
    }

    private function fallbackTitle(string $event): string
    {
        $label = str_replace(['.', '_'], ' ', $event);

        return ucwords(trim($label));
    }

    /**
     * @return array<int, string>
     */
    public function categoriesForFilter(string $targetType): array
    {
        return match ($targetType) {
            'donation' => ['offerings'],
            'donor' => ['donors'],
            'payment', 'payment_batch', 'refund' => ['payments'],
            'recurring_schedule' => ['recurring'],
            'plan', 'due' => ['plans'],
            'project', 'project_installment' => ['projects'],
            'donation_category' => ['categories'],
            'fund', 'donation_settings' => ['settings'],
            'report_export' => ['reports'],
            default => ['general'],
        };
    }
}
