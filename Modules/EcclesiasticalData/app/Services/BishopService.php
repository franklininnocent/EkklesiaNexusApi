<?php

namespace Modules\EcclesiasticalData\Services;

use Modules\EcclesiasticalData\Exceptions\EcclesiasticalDomainException;
use Modules\EcclesiasticalData\Models\BishopAppointment;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Repositories\BishopRepository;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\BishopNameNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BishopService
{
    private const PAGINATED_CACHE_KEYS = 'bishops.paginated.keys';

    private const DIOCESE_CACHE_KEYS = 'bishops.diocese.keys';

    private const TITLE_CACHE_KEYS = 'bishops.title.keys';

    public function __construct(
        protected BishopRepository $repository,
        protected BishopDuplicateDetectionService $duplicateDetection,
        protected EpiscopalAppointmentService $appointmentService,
        protected BishopNameNormalizer $nameNormalizer,
    ) {}

    /**
     * Get paginated bishops with filters
     */
    public function getPaginated(array $params)
    {
        if ($this->shouldBypassPaginationCache($params)) {
            return $this->repository->getBishopsPaginated($params);
        }

        $cacheKey = 'bishops.paginated.' . md5(json_encode($params));

        $paginator = Cache::remember($cacheKey, 300, function () use ($params) {
            return $this->repository->getBishopsPaginated($params);
        });

        $this->rememberCacheKey(self::PAGINATED_CACHE_KEYS, $cacheKey);

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    protected function shouldBypassPaginationCache(array $params): bool
    {
        return ! empty($params['search'])
            || ! empty($params['diocese_id'])
            || ! empty($params['title_id'])
            || ! empty($params['status'])
            || array_key_exists('is_active', $params)
            || array_key_exists('is_current', $params);
    }

    /**
     * Get bishop by ID with relationships
     */
    public function getById(string $id)
    {
        return $this->repository->findWithRelations($id);
    }

    /**
     * Create new bishop (person + optional legacy fields).
     */
    public function create(array $data)
    {
        return $this->createPerson($data, null, true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPerson(array $data, ?int $actorId = null, bool $checkDuplicates = true): BishopManagement
    {
        return DB::transaction(function () use ($data, $actorId, $checkDuplicates) {
            unset($data['id']);

            $data['normalized_name'] = $this->nameNormalizer->normalize($data['full_name'] ?? null);

            if (($data['status'] ?? null) === 'inactive') {
                $data['is_current'] = false;
            }

            if ($checkDuplicates) {
                $duplicates = $this->duplicateDetection->findHighConfidenceDuplicates($data);

                if ($duplicates->isNotEmpty()) {
                    throw EcclesiasticalDomainException::conflict(
                        'A bishop with similar identity already exists. Review before creating a new record.',
                        ['duplicate_bishop_ids' => $duplicates->pluck('id')->all()]
                    );
                }
            }

            $bishop = $this->repository->create($data);
            $this->clearCache();

            return $bishop;
        });
    }

    /**
     * @param  array<string, mixed>  $appointmentData
     */
    public function createAppointmentForBishop(
        BishopManagement $bishop,
        array $appointmentData,
        ?int $actorId = null,
    ): BishopAppointment {
        return $this->appointmentService->create(array_merge($appointmentData, [
            'bishop_id' => $bishop->id,
        ]), $actorId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePersonRecord(
        BishopManagement $bishop,
        array $data,
        ?int $actorId = null,
    ): BishopManagement {
        return DB::transaction(function () use ($bishop, $data, $actorId) {
            if (isset($data['full_name'])) {
                $data['normalized_name'] = $this->nameNormalizer->normalize($data['full_name']);
            }

            $previousStatus = $bishop->status;

            unset($data['id'], $data['archdiocese_id'], $data['is_current']);
            $bishop->update($data);
            $bishop->refresh();

            if (
                isset($data['status'])
                && $data['status'] !== $previousStatus
                && in_array($data['status'], ['retired', 'deceased', 'transferred', 'emeritus'], true)
            ) {
                $this->endCurrentAppointmentsForInactiveBishop($bishop, $data['status'], $actorId);
            }

            $this->clearCache((string) $bishop->id);

            return $bishop->fresh();
        });
    }

    private function endCurrentAppointmentsForInactiveBishop(
        BishopManagement $bishop,
        string $status,
        ?int $actorId = null,
    ): void {
        $reason = match ($status) {
            'deceased' => AppointmentEndReason::Death,
            'transferred' => AppointmentEndReason::Transfer,
            default => AppointmentEndReason::Retirement,
        };

        $appointments = BishopAppointment::query()
            ->where('bishop_id', $bishop->id)
            ->where('is_current', true)
            ->get();

        foreach ($appointments as $appointment) {
            $endDate = $bishop->retired_date?->toDateString() ?? now()->toDateString();
            $this->appointmentService->end($appointment, $endDate, $reason, $actorId);
        }
    }

    /**
     * @param  array<string, mixed>  $appointmentData
     */
    public function updateCurrentAppointment(
        BishopManagement $bishop,
        int $dioceseId,
        array $appointmentData,
        ?int $actorId = null,
    ): BishopAppointment {
        $appointment = BishopAppointment::query()
            ->where('bishop_id', $bishop->id)
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->firstOrFail();

        $this->appointmentService->validateTemporalDates(array_merge(
            $appointment->only(['effective_date', 'appointed_date', 'installed_date', 'ended_date', 'announced_date']),
            $appointmentData
        ));

        $appointment->fill(array_merge($appointmentData, ['updated_by' => $actorId]));
        $appointment->save();

        return $appointment->fresh(['bishop', 'diocese']);
    }

    /**
     * Update bishop
     */
    public function update(string $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            if (isset($data['full_name'])) {
                $data['normalized_name'] = $this->nameNormalizer->normalize($data['full_name']);
            }

            $bishop = $this->repository->update($id, $data);
            $this->clearCache($id);

            return $bishop;
        });
    }

    /**
     * Delete bishop
     */
    public function delete(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $bishop = $this->repository->findOrFail($id);

            if ($bishop->appointments()->exists()) {
                throw EcclesiasticalDomainException::conflict(
                    'Bishops with appointment history cannot be deleted. Archive the record instead.'
                );
            }

            $result = $this->repository->delete($id);
            $this->clearCache($id);

            return $result;
        });
    }

    /**
     * Get bishops by diocese
     */
    public function getByDiocese(string $dioceseId, bool $currentOnly = true)
    {
        $cacheKey = "bishops.diocese.{$dioceseId}." . ($currentOnly ? 'current' : 'all');

        $bishops = Cache::remember($cacheKey, 600, function () use ($dioceseId, $currentOnly) {
            return $this->repository->getByDiocese($dioceseId, $currentOnly);
        });

        $this->rememberCacheKey(self::DIOCESE_CACHE_KEYS, $cacheKey);

        return $bishops;
    }

    /**
     * Get bishops by title
     */
    public function getByTitle(string $titleId)
    {
        $cacheKey = "bishops.title.{$titleId}";

        $bishops = Cache::remember($cacheKey, 600, function () use ($titleId) {
            return $this->repository->getByTitle($titleId);
        });

        $this->rememberCacheKey(self::TITLE_CACHE_KEYS, $cacheKey);

        return $bishops;
    }

    /**
     * Get statistics
     */
    public function getStatistics()
    {
        return Cache::remember('bishops.statistics', 600, function () {
            return $this->repository->getStatistics();
        });
    }

    /**
     * Bulk import bishops
     */
    public function bulkImport(array $bishops)
    {
        return DB::transaction(function () use ($bishops) {
            $imported = 0;
            $errors = [];

            foreach ($bishops as $index => $bishopData) {
                try {
                    $this->createPerson($bishopData, null, false);
                    $imported++;
                } catch (\Exception $e) {
                    $errors[] = [
                        'row' => $index + 1,
                        'data' => $bishopData,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $this->clearCache();

            return [
                'imported' => $imported,
                'errors' => $errors,
                'total' => count($bishops),
            ];
        });
    }

    /**
     * Invalidate list and diocese bishop caches after appointment changes.
     */
    public function invalidateListCaches(?string $bishopId = null): void
    {
        $this->clearCache($bishopId);
    }

    /**
     * Clear bishop cache
     */
    protected function clearCache(?string $bishopId = null): void
    {
        Cache::forget('bishops.statistics');

        if ($bishopId) {
            Cache::forget("bishop.{$bishopId}.full");
        }

        $this->forgetStoredCacheKeys(self::PAGINATED_CACHE_KEYS);
        $this->forgetStoredCacheKeys(self::DIOCESE_CACHE_KEYS);
        $this->forgetStoredCacheKeys(self::TITLE_CACHE_KEYS);
    }

    protected function rememberCacheKey(string $setKey, string $cacheKey): void
    {
        $keys = Cache::get($setKey, []);

        if (! in_array($cacheKey, $keys, true)) {
            $keys[] = $cacheKey;

            if (count($keys) > 50) {
                $keys = array_slice($keys, -50);
            }

            Cache::forever($setKey, $keys);
        }
    }

    protected function forgetStoredCacheKeys(string $setKey): void
    {
        $keys = Cache::pull($setKey, []);

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }
}
