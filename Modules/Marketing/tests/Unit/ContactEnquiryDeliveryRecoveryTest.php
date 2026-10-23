<?php

namespace Modules\Marketing\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Mail\ContactEnquiryNotificationMail;
use Modules\Marketing\Models\ContactEnquiry;
use Modules\Marketing\Services\ContactEnquiryService;
use Tests\TestCase;

class ContactEnquiryDeliveryRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_stuck_received_enquiry_can_retry_email(): void
    {
        $enquiry = ContactEnquiry::factory()->create([
            'delivery_status' => ContactEnquiry::DELIVERY_RECEIVED,
            'created_at' => now()->subMinutes(10),
        ]);

        $service = app(ContactEnquiryService::class);
        $this->assertTrue($service->canRetryEmailDelivery($enquiry));
    }

    public function test_deliver_stuck_pending_notifications_sends_mail(): void
    {
        Mail::fake();
        $enquiry = ContactEnquiry::factory()->create([
            'delivery_status' => ContactEnquiry::DELIVERY_RECEIVED,
            'created_at' => now()->subMinutes(10),
        ]);

        $result = app(ContactEnquiryService::class)->deliverStuckPendingNotifications();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(ContactEnquiry::DELIVERY_SENT, $enquiry->fresh()->delivery_status);
        Mail::assertSent(ContactEnquiryNotificationMail::class);
    }

    public function test_delivery_health_summary_counts_stuck_received(): void
    {
        ContactEnquiry::factory()->create([
            'delivery_status' => ContactEnquiry::DELIVERY_RECEIVED,
            'created_at' => now()->subMinutes(10),
        ]);
        ContactEnquiry::factory()->create([
            'delivery_status' => ContactEnquiry::DELIVERY_FAILED,
        ]);

        $summary = app(ContactEnquiryService::class)->deliveryHealthSummary();

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['stuck_received']);
    }
}
