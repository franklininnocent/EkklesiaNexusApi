<?php

namespace Tests\Unit;

use App\Support\ApiErrorResponse;
use PHPUnit\Framework\TestCase;

class ApiErrorResponseSafetyTest extends TestCase
{
    public function test_technical_exception_text_is_unsafe_to_show(): void
    {
        $this->assertTrue(ApiErrorResponse::isUnsafe('SQLSTATE[23505]: duplicate key value violates unique constraint "users_email_key"'));
        $this->assertTrue(ApiErrorResponse::isUnsafe('Token generation failed: SQLSTATE[08006] connection refused'));
        $this->assertTrue(ApiErrorResponse::isUnsafe('Error in /var/www/html/EkklesiaNexusApi/vendor/laravel/framework/src/Illuminate/Database/Connection.php'));
    }

    public function test_user_facing_copy_is_kept(): void
    {
        $this->assertFalse(ApiErrorResponse::isUnsafe(ApiErrorResponse::LOGIN_INVALID));
        $this->assertFalse(ApiErrorResponse::isUnsafe(ApiErrorResponse::SESSION_EXPIRED));
        $this->assertFalse(ApiErrorResponse::isUnsafe('Enter an amount greater than zero.'));
        $this->assertFalse(ApiErrorResponse::isUnsafe('Current password is incorrect.'));
    }
}
