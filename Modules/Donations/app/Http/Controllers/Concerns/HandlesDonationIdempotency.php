<?php

namespace Modules\Donations\Http\Controllers\Concerns;

use App\Support\ApiErrorResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Donations\Exceptions\DonationDomainException;
use Modules\Donations\Services\DonationIdempotencyService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait HandlesDonationIdempotency
{
    /**
     * @param  callable(): array{status: int, message: string, data: mixed}  $callback
     */
    protected function withIdempotency(
        DonationIdempotencyService $idempotency,
        int $tenantId,
        string $operation,
        Request $request,
        callable $callback
    ): JsonResponse {
        try {
            return DB::transaction(function () use ($idempotency, $tenantId, $operation, $request, $callback): JsonResponse {
                $gate = $idempotency->begin($tenantId, $operation, $request);
                if ($gate && $gate['replay']) {
                    if (is_array($gate['record']->response_body)) {
                        $body = $gate['record']->response_body;
                        $status = (int) ($gate['record']->http_status ?: 200);

                        return response()->json($body, $status);
                    }

                    throw new ConflictHttpException('A payment with this idempotency key is already in progress.');
                }

                $result = $callback();
                $payload = [
                    'success' => true,
                    'message' => $result['message'],
                    'data' => $result['data'],
                ];

                if ($gate) {
                    $resourceId = is_object($result['data']) && isset($result['data']->id)
                        ? (string) $result['data']->id
                        : (string) ($result['resource_id'] ?? '');
                    $idempotency->complete(
                        $gate['record'],
                        $result['resource_type'] ?? $operation,
                        $resourceId,
                        (int) $result['status'],
                        $payload
                    );
                }

                return response()->json($payload, (int) $result['status']);
            });
        } catch (DonationDomainException $exception) {
            return response()->json([
                'success' => false,
                'code' => $exception->errorCode,
                'message' => ApiErrorResponse::publicMessage($exception, ApiErrorResponse::VALIDATION_SUMMARY),
            ], $exception->status);
        } catch (ConflictHttpException $exception) {
            return response()->json([
                'success' => false,
                'code' => ApiErrorResponse::CONFLICT,
                'message' => ApiErrorResponse::publicMessage(
                    $exception,
                    'A payment with this idempotency key is already in progress.'
                ),
            ], 409);
        } catch (ModelNotFoundException|ValidationException|QueryException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'code' => ApiErrorResponse::BUSINESS_RULE,
                'message' => ApiErrorResponse::publicMessage($exception, ApiErrorResponse::VALIDATION_SUMMARY),
            ], 422);
        }
    }
}
