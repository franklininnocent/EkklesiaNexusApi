<?php

namespace Modules\Donations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Services\DonationNotificationService;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;

class SendContributionReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $tenantId, private readonly string $dueId)
    {
    }

    public function handle(DonationNotificationService $notificationService): void
    {
        $due = ContributionDue::forTenant($this->tenantId)->find($this->dueId);
        if (! $due || ! ContributionBalance::isCollectableOverdue($due, DonationBusinessDate::today($this->tenantId))) {
            return;
        }

        $notificationService->queue(
            $this->tenantId,
            'due.upcoming',
            'in_app',
            null,
            [
                'due_id' => $due->id,
                'family_id' => $due->family_id,
                'amount_due' => $due->amount_due,
                'due_date' => $due->due_date?->toDateString(),
            ],
            'due',
            $due->id
        );
    }
}
