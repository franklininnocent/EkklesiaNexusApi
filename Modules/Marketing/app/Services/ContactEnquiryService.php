<?php

namespace Modules\Marketing\Services;

use App\Support\UserFacingDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Marketing\Jobs\SendContactEnquiryNotificationJob;
use Modules\Marketing\Models\ContactEnquiry;
use Modules\Marketing\Models\ContactEnquiryDeliveryEvent;
use Modules\Marketing\Models\WebSettingsAuditLog;
use RuntimeException;
use Throwable;

class ContactEnquiryService
{
    public function __construct(
        private readonly WebSettingsService $webSettings,
        private readonly ContactEnquiryNotificationRecipient $notificationRecipient,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function submit(array $validated, ?string $ip, ?string $correlationId): ContactEnquiry
    {
        $settings = $this->webSettings->current();
        if (! $settings->contact_form_enabled) {
            throw new RuntimeException('contact_form_disabled');
        }

        $enquiry = DB::transaction(function () use ($validated, $ip, $correlationId) {
            $record = ContactEnquiry::query()->create([
                'reference_number' => $this->uniqueReference(),
                'full_name' => $this->sanitizeLine((string) $validated['name']),
                'email' => strtolower(trim((string) $validated['email'])),
                'phone' => $this->nullableLine($validated['phone'] ?? null),
                'organisation' => $this->nullableLine($validated['organisation'] ?? null),
                'subject' => $this->resolveSubject($validated['subject'] ?? null, (string) $validated['message']),
                'message' => trim((string) $validated['message']),
                'enquiry_status' => ContactEnquiry::STATUS_UNREAD,
                'delivery_status' => ContactEnquiry::DELIVERY_RECEIVED,
                'correlation_id' => is_string($correlationId) ? substr($correlationId, 0, 64) : null,
                'source_ip_hash' => $this->hashIp($ip),
            ]);

            $this->recordEvent($record, ContactEnquiry::DELIVERY_RECEIVED, 0, 'Enquiry stored.');

            return $record;
        });

        Log::info('web.contact_enquiry.received', [
            'enquiry_id' => $enquiry->id,
            'reference_number' => $enquiry->reference_number,
            'correlation_id' => $enquiry->correlation_id,
        ]);

        if ($this->notificationRecipient->email() === null) {
            $enquiry->delivery_status = ContactEnquiry::DELIVERY_FAILED;
            $enquiry->last_delivery_error = 'Contact enquiry recipient email is not configured.';
            $enquiry->save();
            $this->recordEvent($enquiry, ContactEnquiry::DELIVERY_FAILED, 0, 'Contact enquiry recipient email is not configured.');
            Log::error('web.contact_enquiry.notification_recipient_missing', [
                'enquiry_id' => $enquiry->id,
                'reference_number' => $enquiry->reference_number,
            ]);

            return $enquiry->fresh();
        }

        // SMTP connect runs after the response is flushed. A blocked port must not
        // hold this request open until the public gateway returns 502.
        SendContactEnquiryNotificationJob::dispatchAfterResponse($enquiry->id);

        Log::info('web.contact_enquiry.response_ready', [
            'enquiry_id' => $enquiry->id,
            'dispatch' => 'after_response',
        ]);

        return $enquiry->fresh();
    }

    public function sendStoredEnquiryNotification(ContactEnquiry $enquiry): void
    {
        (new SendContactEnquiryNotificationJob($enquiry->id))->handle($this, $this->notificationRecipient);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = ContactEnquiry::query();

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($inner) use ($like) {
                foreach (['reference_number', 'full_name', 'email', 'phone', 'organisation', 'subject'] as $column) {
                    $inner->orWhereRaw('LOWER(COALESCE('.$column.", '')) LIKE ?", [$like]);
                }
            });
        }

        if (! empty($filters['enquiry_status'])) {
            $query->where('enquiry_status', $filters['enquiry_status']);
        }
        if (! empty($filters['delivery_status'])) {
            $query->where('delivery_status', $filters['delivery_status']);
        }
        if (! empty($filters['submitted_from'])) {
            $query->whereDate('created_at', '>=', $filters['submitted_from']);
        }
        if (! empty($filters['submitted_to'])) {
            $query->whereDate('created_at', '<=', $filters['submitted_to']);
        }

        $sortable = [
            'created_at' => 'created_at',
            'full_name' => 'full_name',
            'email' => 'email',
            'enquiry_status' => 'enquiry_status',
            'delivery_status' => 'delivery_status',
            'reference_number' => 'reference_number',
        ];
        $sort = $sortable[$filters['sort'] ?? 'created_at'] ?? 'created_at';
        $dir = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(10, min(100, $perPage));

        return $query->paginate($perPage);
    }

    public function find(string $id): ContactEnquiry
    {
        return ContactEnquiry::query()->with('deliveryEvents')->findOrFail($id);
    }

    public function updateStatus(ContactEnquiry $enquiry, string $status, User $actor): ContactEnquiry
    {
        if (! in_array($status, ContactEnquiry::enquiryStatuses(), true)) {
            throw new RuntimeException('invalid_status');
        }

        $from = $enquiry->enquiry_status;
        $enquiry->enquiry_status = $status;
        if ($status === ContactEnquiry::STATUS_READ && $enquiry->read_at === null) {
            $enquiry->read_at = now();
            $enquiry->read_by = $actor->id;
        }
        if ($status === ContactEnquiry::STATUS_UNREAD) {
            $enquiry->read_at = null;
            $enquiry->read_by = null;
        }
        $enquiry->save();

        WebSettingsAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => 'enquiry.status',
            'changes' => [
                'enquiry_id' => $enquiry->id,
                'from' => $from,
                'to' => $status,
            ],
        ]);

        return $enquiry->fresh();
    }

    public function markReadState(ContactEnquiry $enquiry, bool $read, User $actor): ContactEnquiry
    {
        if ($read) {
            if ($enquiry->enquiry_status === ContactEnquiry::STATUS_UNREAD) {
                $enquiry->enquiry_status = ContactEnquiry::STATUS_READ;
            }
            $enquiry->read_at = now();
            $enquiry->read_by = $actor->id;
        } else {
            if (in_array($enquiry->enquiry_status, [ContactEnquiry::STATUS_READ, ContactEnquiry::STATUS_UNREAD], true)) {
                $enquiry->enquiry_status = ContactEnquiry::STATUS_UNREAD;
            }
            $enquiry->read_at = null;
            $enquiry->read_by = null;
        }
        $enquiry->save();

        return $enquiry->fresh();
    }

    public function retryDelivery(ContactEnquiry $enquiry, User $actor): ContactEnquiry
    {
        if ($enquiry->delivery_status === ContactEnquiry::DELIVERY_SENT) {
            throw new RuntimeException('already_sent');
        }

        if ($this->notificationRecipient->email() === null) {
            throw new RuntimeException('recipient_missing');
        }

        $enquiry->last_delivery_error = null;
        $enquiry->save();

        $this->sendStoredEnquiryNotification($enquiry);

        WebSettingsAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => 'enquiry.retry_email',
            'changes' => ['enquiry_id' => $enquiry->id],
        ]);

        return $enquiry->fresh();
    }

    public function archiveOrDelete(ContactEnquiry $enquiry, User $actor, bool $hardDelete = false): void
    {
        WebSettingsAuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $hardDelete ? 'enquiry.delete' : 'enquiry.archive',
            'changes' => [
                'enquiry_id' => $enquiry->id,
                'reference_number' => $enquiry->reference_number,
            ],
        ]);

        if ($hardDelete) {
            $enquiry->delete();

            return;
        }

        $enquiry->enquiry_status = ContactEnquiry::STATUS_ARCHIVED;
        $enquiry->save();
    }

    public function markQueued(ContactEnquiry $enquiry): void
    {
        $enquiry->delivery_status = ContactEnquiry::DELIVERY_QUEUED;
        $enquiry->save();
        $this->recordEvent($enquiry, ContactEnquiry::DELIVERY_QUEUED, (int) $enquiry->delivery_attempts, 'Queued for email transport.');
    }

    public function markSent(ContactEnquiry $enquiry): void
    {
        $enquiry->delivery_status = ContactEnquiry::DELIVERY_SENT;
        $enquiry->delivery_attempts = (int) $enquiry->delivery_attempts + 1;
        $enquiry->last_delivered_at = now();
        $enquiry->last_delivery_error = null;
        $enquiry->save();
        $this->recordEvent($enquiry, ContactEnquiry::DELIVERY_SENT, (int) $enquiry->delivery_attempts, 'Accepted by mail transport.');
    }

    public function markFailed(ContactEnquiry $enquiry, string $safeError): void
    {
        $enquiry->delivery_status = ContactEnquiry::DELIVERY_FAILED;
        $enquiry->delivery_attempts = (int) $enquiry->delivery_attempts + 1;
        $enquiry->last_delivery_error = $this->sanitizeError($safeError);
        $enquiry->save();
        $this->recordEvent($enquiry, ContactEnquiry::DELIVERY_FAILED, (int) $enquiry->delivery_attempts, $enquiry->last_delivery_error);
    }

    public function recordEvent(ContactEnquiry $enquiry, string $status, int $attempt, ?string $detail): void
    {
        ContactEnquiryDeliveryEvent::query()->create([
            'contact_enquiry_id' => $enquiry->id,
            'status' => $status,
            'attempt' => $attempt,
            'detail' => $detail !== null ? $this->sanitizeError($detail) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(ContactEnquiry $enquiry, bool $includeMessage = true): array
    {
        $row = [
            'id' => $enquiry->id,
            'reference_number' => $enquiry->reference_number,
            'full_name' => $enquiry->full_name,
            'email' => $enquiry->email,
            'phone' => $enquiry->phone,
            'organisation' => $enquiry->organisation,
            'subject' => $enquiry->subject ?: $this->resolveSubject(null, (string) $enquiry->message),
            'enquiry_status' => $enquiry->enquiry_status,
            'delivery_status' => $enquiry->delivery_status,
            'delivery_attempts' => (int) $enquiry->delivery_attempts,
            'last_delivered_at' => $enquiry->last_delivered_at?->toIso8601String(),
            'last_delivered_at_label' => UserFacingDate::formatDateTime($enquiry->last_delivered_at),
            'last_delivery_error' => $enquiry->last_delivery_error,
            'submitted_at' => $enquiry->created_at?->toIso8601String(),
            'submitted_at_label' => UserFacingDate::formatDateTime($enquiry->created_at),
            'read_at' => $enquiry->read_at?->toIso8601String(),
            'can_retry_email' => $enquiry->delivery_status === ContactEnquiry::DELIVERY_FAILED,
        ];

        if ($includeMessage) {
            $row['message'] = $enquiry->message;
            $row['delivery_events'] = $enquiry->deliveryEvents
                ->sortBy('created_at')
                ->values()
                ->map(fn (ContactEnquiryDeliveryEvent $event) => [
                    'status' => $event->status,
                    'attempt' => (int) $event->attempt,
                    'detail' => $event->detail,
                    'created_at' => $event->created_at?->toIso8601String(),
                    'created_at_label' => UserFacingDate::formatDateTime($event->created_at),
                ])
                ->all();
        }

        return $row;
    }

    private function uniqueReference(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $candidate = 'ENQ-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
            if (! ContactEnquiry::query()->where('reference_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'ENQ-'.now()->format('Ymd').'-'.strtoupper(Str::random(10));
    }

    private function sanitizeLine(string $value): string
    {
        $clean = str_replace(["\r", "\n", "\0"], ' ', $value);

        return trim($clean);
    }

    private function nullableLine(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = $this->sanitizeLine($value);

        return $clean === '' ? null : $clean;
    }

    private function resolveSubject(mixed $subject, string $message): string
    {
        $explicit = $this->nullableLine($subject);
        if ($explicit !== null) {
            return $explicit;
        }

        $normalized = preg_replace("/\r\n|\r/", "\n", $message) ?? $message;
        $firstLine = strtok($normalized, "\n");
        $preview = $this->sanitizeLine(is_string($firstLine) ? $firstLine : '');
        if ($preview === '') {
            return 'Website contact enquiry';
        }

        return mb_strlen($preview) > 200 ? mb_substr($preview, 0, 200) : $preview;
    }

    private function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', $ip, (string) config('app.key'));
    }

    private function sanitizeError(string $message): string
    {
        $trimmed = substr($message, 0, 500);
        $trimmed = preg_replace('/(password|secret|api[_-]?key|token)\s*[:=]\s*\S+/i', '$1=[redacted]', $trimmed) ?? $trimmed;

        return $trimmed;
    }

    public function handleJobException(Throwable $e, ContactEnquiry $enquiry): void
    {
        Log::warning('web.contact_enquiry.mail_failed', [
            'enquiry_id' => $enquiry->id,
            'reference_number' => $enquiry->reference_number,
            'message' => $e->getMessage(),
        ]);
        $this->markFailed($enquiry, 'Email transport failed. The enquiry is stored and can be retried.');
    }
}
