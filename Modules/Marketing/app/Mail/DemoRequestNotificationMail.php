<?php

namespace Modules\Marketing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Marketing\Models\MarketingDemoRequest;

class DemoRequestNotificationMail extends Mailable
{
    public function __construct(public readonly MarketingDemoRequest $demoRequest) {}

    public function envelope(): Envelope
    {
        $visitorEmail = $this->safeEmail($this->demoRequest->email);
        $envelope = new Envelope(
            subject: (string) config('email.product_name', 'EkklesiaNexus').' — Demo request',
        );

        if ($visitorEmail !== null) {
            $envelope->replyTo([
                new Address($visitorEmail, $this->safeName($this->demoRequest->name)),
            ]);
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'marketing::emails.demo-request-notification',
            with: [
                'demo' => $this->demoRequest,
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
