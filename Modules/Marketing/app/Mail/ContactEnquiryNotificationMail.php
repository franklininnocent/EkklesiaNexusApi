<?php

namespace Modules\Marketing\Mail;

use App\Support\UserFacingDate;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Marketing\Models\ContactEnquiry;
use Modules\Marketing\Services\ContactEnquiryNotificationRecipient;

class ContactEnquiryNotificationMail extends Mailable
{
    public function __construct(public readonly ContactEnquiry $enquiry) {}

    public function envelope(): Envelope
    {
        $product = (string) config('email.product_name', 'EkklesiaNexus');
        $visitorEmail = $this->safeEmail($this->enquiry->email);

        $envelope = new Envelope(
            subject: 'New Contact Enquiry Received — '.$product,
        );

        if ($visitorEmail !== null) {
            $envelope->replyTo([
                new Address($visitorEmail, $this->safeName($this->enquiry->full_name)),
            ]);
        }

        return $envelope;
    }

    public function content(): Content
    {
        $recipient = app(ContactEnquiryNotificationRecipient::class);

        return new Content(
            view: 'marketing::emails.contact-enquiry-notification',
            text: 'marketing::emails.contact-enquiry-notification-text',
            with: [
                'enquiry' => $this->enquiry,
                'submittedAtLabel' => UserFacingDate::formatDateTime($this->enquiry->created_at),
                'preheader' => 'New website contact enquiry '.$this->enquiry->reference_number,
                'adminEnquiriesUrl' => $recipient->adminEnquiriesUrl(),
                'brandNavy' => '#003070',
                'brandGold' => '#E0A830',
                'productTagline' => 'The Next Generation of Church Management System',
            ],
        );
    }

    private function safeEmail(string $email): ?string
    {
        $clean = strtolower(trim(str_replace(["\r", "\n"], '', $email)));

        return filter_var($clean, FILTER_VALIDATE_EMAIL) ?: null;
    }

    private function safeName(string $name): string
    {
        $clean = trim(str_replace(["\r", "\n"], ' ', $name));

        return mb_substr($clean, 0, 80);
    }
}
