<?php

namespace Modules\Authentication\Http\Controllers;

use App\Support\ApiErrorResponse;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Authentication\Http\Requests\AdminResetPasswordRequest;
use Modules\Authentication\Http\Requests\ChangeOwnPasswordRequest;
use Modules\Authentication\Models\User;
use Modules\Authentication\Services\PasswordManagementService;

class PasswordController extends Controller
{
    public function __construct(private readonly PasswordManagementService $passwordManagement)
    {
    }

    public function changeOwn(ChangeOwnPasswordRequest $request): JsonResponse
    {
        try {
            /** @var User $actor */
            $actor = $request->user();
            $tokens = $this->passwordManagement->changeOwnPassword(
                $actor,
                (string) $request->input('current_password'),
                (string) $request->input('password'),
            );

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully.',
                'data' => $tokens,
            ]);
        } catch (\RuntimeException $e) {
            if ($e instanceof \PDOException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to change password due to a server error. Please try again.',
                ], 500);
            }

            $status = in_array($e->getCode(), [403, 422], true) ? (int) $e->getCode() : 422;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        } catch (\Throwable $e) {
            ApiErrorResponse::rethrowExpected($e);
            throw $e;
        }
    }

    public function adminReset(AdminResetPasswordRequest $request, int $id): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if ($actor->tenant_id !== null) {
            $target = User::query()
                ->where('tenant_id', $actor->tenant_id)
                ->whereKey($id)
                ->first();

            if (! $target) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }
        } else {
            $target = User::query()->whereKey($id)->first();

            if (! $target) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found.',
                ], 404);
            }
        }

        try {
            $result = $this->passwordManagement->adminResetPassword($actor, $target);

            return response()->json([
                'success' => true,
                'message' => 'Password reset successfully. Share the temporary password securely with the user.',
                'data' => [
                    'temporary_password' => $result['temporary_password'],
                    'force_password_change' => true,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = in_array($e->getCode(), [403, 422], true) ? (int) $e->getCode() : 403;

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $status);
        }
    }
}
