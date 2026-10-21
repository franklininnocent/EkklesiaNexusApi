<?php

namespace Tests\Feature;

use App\Support\ApiErrorResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        Route::middleware('api')->prefix('api')->group(function () {
            Route::get('/__error-contract/boom', function () {
                throw new RuntimeException('SQLSTATE[08006] password=secret token=abc /var/www/vendor/laravel');
            });

            Route::get('/__error-contract/missing', function () {
                throw (new ModelNotFoundException)->setModel('Modules\\Family\\Models\\Family', [99]);
            });

            Route::post('/__error-contract/validate', function () {
                request()->validate([
                    'email' => ['required', 'email'],
                    'amount' => ['required', 'numeric'],
                ]);
            });

            Route::get('/__error-contract/duplicate', function () {
                $previous = new PDOException('duplicate key value violates unique constraint users_email_key');
                $previous->errorInfo = ['23505', '23505', 'duplicate'];

                throw new UniqueConstraintViolationException('pgsql', 'insert into users (email) values (?)', ['a@b.c'], $previous);
            });

            Route::get('/__error-contract/foreign-key', function () {
                $previous = new PDOException('insert or update on table families violates foreign key constraint');
                $previous->errorInfo = ['23503', '23503', 'foreign key'];

                throw new QueryException('pgsql', 'insert into families (tenant_id) values (?)', [1], $previous);
            });
        });
    }

    #[Test]
    public function unauthenticated_requests_use_a_session_message_without_internal_detail(): void
    {
        $this->getJson('/api/families/dashboard')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', ApiErrorResponse::UNAUTHENTICATED)
            ->assertJsonPath('message', ApiErrorResponse::SESSION_EXPIRED)
            ->assertJsonStructure(['request_id']);
    }

    #[Test]
    public function unexpected_exceptions_are_masked_when_debug_is_off(): void
    {
        $response = $this->getJson('/api/__error-contract/boom');

        $response->assertStatus(500)
            ->assertJsonPath('code', ApiErrorResponse::UNEXPECTED)
            ->assertJsonPath('message', ApiErrorResponse::UNEXPECTED_MESSAGE);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertStringNotContainsString('trace', strtolower($body));
    }

    #[Test]
    public function unexpected_exceptions_are_masked_even_when_debug_is_enabled(): void
    {
        config(['app.debug' => true]);

        $response = $this->getJson('/api/__error-contract/boom');

        $response->assertStatus(500)
            ->assertJsonPath('code', ApiErrorResponse::UNEXPECTED)
            ->assertJsonPath('message', ApiErrorResponse::UNEXPECTED_MESSAGE);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertStringNotContainsString('exception', strtolower($body));
        $this->assertStringNotContainsString('stack', strtolower($body));
    }

    #[Test]
    public function missing_records_do_not_reveal_model_names(): void
    {
        $response = $this->getJson('/api/__error-contract/missing');

        $response->assertNotFound()
            ->assertJsonPath('code', ApiErrorResponse::NOT_FOUND)
            ->assertJsonPath('message', ApiErrorResponse::NOT_FOUND_MESSAGE);

        $this->assertStringNotContainsString('Family', (string) $response->getContent());
    }

    #[Test]
    public function validation_errors_keep_field_keys_and_a_safe_summary(): void
    {
        $this->postJson('/api/__error-contract/validate', [])
            ->assertStatus(422)
            ->assertJsonPath('code', ApiErrorResponse::VALIDATION_FAILED)
            ->assertJsonPath('message', ApiErrorResponse::VALIDATION_SUMMARY)
            ->assertJsonValidationErrors(['email', 'amount']);
    }

    #[Test]
    public function unique_violations_do_not_expose_constraint_names(): void
    {
        $response = $this->getJson('/api/__error-contract/duplicate');

        $response->assertStatus(409)
            ->assertJsonPath('code', ApiErrorResponse::DUPLICATE)
            ->assertJsonPath('message', ApiErrorResponse::DUPLICATE_MESSAGE);

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('users_email_key', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
    }

    #[Test]
    public function foreign_key_violations_use_a_safe_conflict_message(): void
    {
        $response = $this->getJson('/api/__error-contract/foreign-key');

        $response->assertStatus(409)
            ->assertJsonPath('code', ApiErrorResponse::CONFLICT)
            ->assertJsonPath('message', ApiErrorResponse::FOREIGN_KEY_MESSAGE);

        $this->assertStringNotContainsString('families', (string) $response->getContent());
    }

    #[Test]
    public function unknown_api_routes_are_not_found_without_a_stack_trace(): void
    {
        $response = $this->getJson('/api/__error-contract/does-not-exist');

        $this->assertContains($response->status(), [404, 405]);
        $body = strtolower((string) $response->getContent());
        $this->assertStringNotContainsString('stack', $body);
        $this->assertStringNotContainsString('sqlstate', $body);
        $this->assertStringNotContainsString('vendor/', $body);
    }
}
