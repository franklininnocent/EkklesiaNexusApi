<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Marketing\Http\Requests\BulkDeleteContactEnquiriesRequest;
use Modules\Marketing\Http\Requests\ListContactEnquiriesRequest;
use Modules\Marketing\Http\Requests\UpdateContactEnquiryRequest;
use Modules\Marketing\Services\ContactEnquiryService;
use RuntimeException;

class AdminContactEnquiryController extends Controller
{
    public function __construct(private readonly ContactEnquiryService $enquiries) {}

    public function index(ListContactEnquiriesRequest $request): JsonResponse
    {
        $page = $this->enquiries->paginate($request->validated());

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(
                fn ($enquiry) => $this->enquiries->toAdminArray($enquiry, false)
            )->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(string $enquiry): JsonResponse
    {
        $record = $this->enquiries->find($enquiry);

        return response()->json([
            'success' => true,
            'data' => $this->enquiries->toAdminArray($record, true),
        ]);
    }

    public function update(UpdateContactEnquiryRequest $request, string $enquiry): JsonResponse
    {
        $record = $this->enquiries->find($enquiry);
        $actor = $request->user();

        if ($request->exists('is_read')) {
            $record = $this->enquiries->markReadState($record, (bool) $request->boolean('is_read'), $actor);
        }
        if ($request->filled('enquiry_status')) {
            $record = $this->enquiries->updateStatus($record, (string) $request->validated('enquiry_status'), $actor);
        }

        return response()->json([
            'success' => true,
            'message' => 'Enquiry updated.',
            'data' => $this->enquiries->toAdminArray($record->fresh('deliveryEvents'), true),
        ]);
    }

    public function retryEmail(string $enquiry): JsonResponse
    {
        $record = $this->enquiries->find($enquiry);

        try {
            $record = $this->enquiries->retryDelivery($record, request()->user());
        } catch (RuntimeException $e) {
            $message = match ($e->getMessage()) {
                'already_sent' => 'This enquiry email was already accepted by the mail transport.',
                'recipient_missing' => 'A valid contact recipient is not configured.',
                default => 'Unable to retry email delivery.',
            };

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email delivery has been queued again.',
            'data' => $this->enquiries->toAdminArray($record, true),
        ]);
    }

    public function destroy(string $enquiry): JsonResponse
    {
        $record = $this->enquiries->find($enquiry);
        $this->enquiries->archiveOrDelete($record, request()->user(), true);

        return response()->json([
            'success' => true,
            'message' => 'Enquiry deleted.',
        ]);
    }

    public function bulkDestroy(BulkDeleteContactEnquiriesRequest $request): JsonResponse
    {
        $ids = $request->validated('ids');
        $result = $this->enquiries->bulkDelete($ids, $request->user());

        return response()->json([
            'success' => true,
            'message' => $result['deleted_count'] === 1
                ? '1 enquiry deleted.'
                : $result['deleted_count'].' enquiries deleted.',
            'data' => $result,
        ]);
    }
}
