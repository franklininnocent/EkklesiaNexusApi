<?php

namespace Modules\Marketing\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Jobs\SendContactEnquiryNotificationJob;
use Modules\Marketing\Models\ContactEnquiry;
use RuntimeException;
use Tests\TestCase;

class SendContactEnquiryNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_exception_marks_enquiry_failed_without_escaping_handle(): void
    {
        $enquiry = ContactEnquiry::factory()->create([
            'delivery_status' => ContactEnquiry::DELIVERY_RECEIVED,
        ]);

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP unavailable'));

        $job = new SendContactEnquiryNotificationJob($enquiry->id);
        $job->handle(
            app(\Modules\Marketing\Services\ContactEnquiryService::class),
            app(\Modules\Marketing\Services\ContactEnquiryNotificationRecipient::class),
        );

        $this->assertSame(
            ContactEnquiry::DELIVERY_FAILED,
            $enquiry->fresh()->delivery_status,
        );
    }
}
