<?php

namespace Modules\Marketing\Console;

use Illuminate\Console\Command;
use Modules\Marketing\Services\ContactEnquiryService;

class DeliverPendingContactEnquiryNotificationsCommand extends Command
{
    protected $signature = 'marketing:deliver-pending-contact-notifications
                            {--report : Print delivery counts only; do not send mail}';

    protected $description = 'Send notification email for contact enquiries stuck in received status (after-response dispatch missed).';

    public function handle(ContactEnquiryService $enquiries): int
    {
        $health = $enquiries->deliveryHealthSummary();

        if ($this->option('report')) {
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total enquiries', $health['total']],
                    ['Delivery sent', $health['sent']],
                    ['Delivery failed', $health['failed']],
                    ['Delivery received (pending)', $health['received']],
                    ['Stuck received (older than grace)', $health['stuck_received']],
                ]
            );

            if ($health['stuck_received'] > 0) {
                $this->warn('Stuck enquiries need notification delivery. Run without --report to process them.');
            }

            if ($health['failed'] > 0) {
                $this->warn('Failed enquiries can be retried from Settings → Contact enquiries after mail transport is fixed.');
            }

            return self::SUCCESS;
        }

        $result = $enquiries->deliverStuckPendingNotifications();

        $this->info(sprintf(
            'Processed %d stuck enquiry(ies): %d sent, %d failed.',
            $result['processed'],
            $result['sent'],
            $result['failed'],
        ));

        return self::SUCCESS;
    }
}
