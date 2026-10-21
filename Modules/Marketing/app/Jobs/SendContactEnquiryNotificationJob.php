<?php

namespace Modules\Marketing\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Mail\ContactEnquiryNotificationMail;
use Modules\Marketing\Models\ContactEnquiry;
use Modules\Marketing\Services\ContactEnquiryNotificationRecipient;
use Modules\Marketing\Services\ContactEnquiryService;
use Throwable;

/**
 * Sends the contact enquiry notification after the visitor HTTP response.
 * Runs in-process (not the database queue) so delivery does not depend on a worker.
 */
class SendContactEnquiryNotificationJob
{
    use Dispatchable;

    public function __construct(public readonly string $enquiryId) {}

    public function handle(ContactEnquiryService $enquiries, ContactEnquiryNotificationRecipient $recipientResolver): void
    {
        $enquiry = ContactEnquiry::query()->find($this->enquiryId);
        if ($enquiry === null) {
            return;
        }

        Log::info('web.contact_enquiry.mail_attempt', [
            'enquiry_id' => $enquiry->id,
        ]);

        try {
            $recipientEmail = $recipientResolver->email();
            if ($recipientEmail === null) {
                $enquiries->markFailed($enquiry, 'Contact enquiry recipient email is not configured.');

                return;
            }

            $enquiries->markQueued($enquiry);

            Mail::to(new Address($recipientEmail, $recipientResolver->name()))
                ->send(new ContactEnquiryNotificationMail($enquiry->fresh()));

            $enquiries->markSent($enquiry->fresh());
        } catch (Throwable $e) {
            $this->failed($e);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $enquiry = ContactEnquiry::query()->find($this->enquiryId);
        if ($enquiry === null) {
            return;
        }

        app(ContactEnquiryService::class)->handleJobException(
            $exception ?? new \RuntimeException('Unknown mail failure.'),
            $enquiry
        );
    }
}
