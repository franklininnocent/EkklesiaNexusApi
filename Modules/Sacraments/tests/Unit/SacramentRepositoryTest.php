<?php

namespace Modules\Sacraments\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Repositories\SacramentRepository;
use Modules\Tenants\Models\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SacramentRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected SacramentRepository $repository;

    protected Tenant $tenant;

    protected SacramentType $sacramentType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new SacramentRepository(new Sacrament);
        $this->tenant = Tenant::factory()->create();
        $this->sacramentType = SacramentType::factory()->create();
    }

    /** @test */
    #[Test]
    public function it_can_get_paginated_sacraments()
    {
        // Arrange
        Sacrament::factory()->count(25)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $result = $this->repository->getPaginated(['per_page' => 10]);

        // Assert
        $this->assertEquals(10, $result->count());
        $this->assertEquals(25, $result->total());
        $this->assertInstanceOf(LengthAwarePaginator::class, $result);
    }

    /** @test */
    #[Test]
    public function it_can_filter_by_tenant_id()
    {
        // Arrange
        $otherTenant = Tenant::factory()->create();

        Sacrament::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        Sacrament::factory()->count(3)->create([
            'tenant_id' => $otherTenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $result = $this->repository->getPaginated(['tenant_id' => $this->tenant->id]);

        // Assert
        $this->assertEquals(5, $result->total());
    }

    /** @test */
    #[Test]
    public function it_can_filter_by_sacrament_type()
    {
        // Arrange
        $otherType = SacramentType::factory()->create();

        Sacrament::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        Sacrament::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $otherType->id,
        ]);

        // Act
        $result = $this->repository->getPaginated(['sacrament_type_id' => $this->sacramentType->id]);

        // Assert
        $this->assertEquals(3, $result->total());
    }

    /** @test */
    #[Test]
    public function it_can_filter_by_status()
    {
        // Arrange
        Sacrament::factory()->count(3)->active()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        Sacrament::factory()->count(2)->cancelled()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $result = $this->repository->getPaginated(['status' => 'active']);

        // Assert
        $this->assertEquals(3, $result->total());
    }

    /** @test */
    #[Test]
    public function it_can_search_by_recipient_name()
    {
        // Arrange
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'recipient_name' => 'John Doe',
        ]);

        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'recipient_name' => 'Jane Smith',
        ]);

        // Act
        $result = $this->repository->getPaginated(['search' => 'John']);

        // Assert
        $this->assertEquals(1, $result->total());
        $this->assertStringContainsString('John', $result->first()->recipient_name);
    }

    /** @test */
    #[Test]
    public function it_can_filter_by_date_range()
    {
        // Arrange
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'date_administered' => '2025-06-15',
        ]);

        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'date_administered' => '2024-01-10',
        ]);

        // Act
        $result = $this->repository->getPaginated([
            'date_from' => '2025-01-01',
            'date_to' => '2025-12-31',
        ]);

        // Assert
        $this->assertEquals(1, $result->total());
    }

    /** @test */
    #[Test]
    public function it_can_sort_results()
    {
        // Arrange
        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'date_administered' => '2025-01-01',
        ]);

        Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'date_administered' => '2025-12-31',
        ]);

        // Act
        $result = $this->repository->getPaginated([
            'sort_by' => 'date_administered',
            'sort_dir' => 'desc',
        ]);

        // Assert
        $this->assertSame(
            '2025-12-31',
            $result->first()->date_administered?->format('Y-m-d'),
        );
    }

    /** @test */
    #[Test]
    public function it_can_create_sacrament()
    {
        // Arrange
        $data = [
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'recipient_name' => 'John Doe',
            'date_administered' => '2025-01-15',
            'status' => 'registered',
        ];

        // Act
        $result = $this->repository->create($data);

        // Assert
        $this->assertInstanceOf(Sacrament::class, $result);
        $this->assertEquals('John Doe', $result->recipient_name);
        $this->assertDatabaseHas('sacraments', ['recipient_name' => 'John Doe']);
    }

    /** @test */
    #[Test]
    public function it_can_update_sacrament()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'recipient_name' => 'Original Name',
        ]);

        // Act
        $result = $this->repository->update($sacrament, ['recipient_name' => 'Updated Name']);

        // Assert
        $this->assertEquals('Updated Name', $result->recipient_name);
        $this->assertDatabaseHas('sacraments', [
            'id' => $sacrament->id,
            'recipient_name' => 'Updated Name',
        ]);
    }

    /** @test */
    #[Test]
    public function it_can_delete_sacrament()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $result = $this->repository->delete($sacrament);

        // Assert
        $this->assertTrue($result);
        $this->assertSoftDeleted('sacraments', ['id' => $sacrament->id]);
    }

    /** @test */
    #[Test]
    public function it_can_find_sacrament_by_id()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
            'recipient_name' => 'Test Recipient',
        ]);

        // Act
        $result = $this->repository->findById($sacrament->id);

        // Assert
        $this->assertInstanceOf(Sacrament::class, $result);
        $this->assertEquals($sacrament->id, $result->id);
        $this->assertEquals('Test Recipient', $result->recipient_name);
    }

    /** @test */
    #[Test]
    public function it_returns_null_when_sacrament_not_found()
    {
        // Act
        $result = $this->repository->findById(99999);

        // Assert
        $this->assertNull($result);
    }

    /** @test */
    #[Test]
    public function it_eager_loads_relationships()
    {
        // Arrange
        $sacrament = Sacrament::factory()->create([
            'tenant_id' => $this->tenant->id,
            'sacrament_type_id' => $this->sacramentType->id,
        ]);

        // Act
        $result = $this->repository->findById($sacrament->id);

        // Assert
        $this->assertTrue($result->relationLoaded('sacramentType'));
        $this->assertTrue($result->relationLoaded('tenant'));
    }
}
