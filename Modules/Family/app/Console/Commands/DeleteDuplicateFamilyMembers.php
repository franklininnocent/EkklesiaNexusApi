<?php

namespace Modules\Family\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Family\Models\FamilyMember;

class DeleteDuplicateFamilyMembers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'family:delete-duplicates
                            {--tenant-id= : Required parish tenant id (isolates deletes to one tenant)}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Force deletion without confirmation}
                            {--family-id= : Only process duplicates for a specific family ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete duplicate family members created by the update bug';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $familyId = $this->option('family-id');
        $tenantId = (int) $this->option('tenant-id');

        if ($tenantId <= 0) {
            $this->error('Provide --tenant-id so duplicate deletion cannot cross parish boundaries.');

            return Command::FAILURE;
        }

        $this->info('🔍 Searching for duplicate family members...');
        
        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No records will be deleted');
        }

        // Find duplicates based on family_id, first_name, last_name, and date_of_birth
        $duplicates = $this->findDuplicates($tenantId, $familyId);

        if (empty($duplicates)) {
            $this->info('✅ No duplicate family members found.');
            return Command::SUCCESS;
        }

        $totalDuplicates = 0;
        $totalToDelete = 0;

        foreach ($duplicates as $duplicateGroup) {
            $totalDuplicates += count($duplicateGroup);
            // Keep the oldest record (first created), delete the rest
            $totalToDelete += count($duplicateGroup) - 1;
        }

        $this->info("📊 Found {$totalDuplicates} duplicate records across " . count($duplicates) . " groups");
        $this->info("🗑️  Will delete {$totalToDelete} duplicate records");

        if (!$dryRun && !$force) {
            if (!$this->confirm('Do you want to proceed with deletion?', true)) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $deleted = 0;
        $errors = 0;

        foreach ($duplicates as $duplicateGroup) {
            // Groups are ordered by created_at ascending in findDuplicates()
            $toKeep = array_shift($duplicateGroup);

            foreach ($duplicateGroup as $duplicate) {
                try {
                    if ($dryRun) {
                        $this->line("  [DRY RUN] Would delete: {$duplicate['id']} - {$duplicate['first_name']} {$duplicate['last_name']} (Family: {$duplicate['family_id']})");
                        $deleted++;
                    } else {
                        $member = FamilyMember::query()
                            ->where('tenant_id', $tenantId)
                            ->whereKey($duplicate['id'])
                            ->first();
                        if ($member) {
                            // Log before deletion
                            Log::info('Deleting duplicate family member', [
                                'member_id' => $duplicate['id'],
                                'family_id' => $duplicate['family_id'],
                                'name' => "{$duplicate['first_name']} {$duplicate['last_name']}",
                                'kept_member_id' => $toKeep['id'],
                                'created_at' => $duplicate['created_at']
                            ]);

                            $member->delete(); // Soft delete
                            $this->line("  ✓ Deleted: {$duplicate['id']} - {$duplicate['first_name']} {$duplicate['last_name']}");
                            $deleted++;
                        } else {
                            $this->warn("  ⚠ Member not found: {$duplicate['id']}");
                            $errors++;
                        }
                    }
                } catch (\Exception $e) {
                    $this->error("  ✗ Error deleting {$duplicate['id']}: {$e->getMessage()}");
                    Log::error('Error deleting duplicate family member', [
                        'member_id' => $duplicate['id'],
                        'error' => $e->getMessage()
                    ]);
                    $errors++;
                }
            }

            // Log which record was kept
            if (!$dryRun) {
                $this->line("  ✓ Kept: {$toKeep['id']} - {$toKeep['first_name']} {$toKeep['last_name']} (oldest record)");
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->info("✅ DRY RUN: Would delete {$deleted} duplicate records");
        } else {
            $this->info("✅ Successfully deleted {$deleted} duplicate records");
            if ($errors > 0) {
                $this->warn("⚠️  {$errors} errors occurred during deletion");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Find duplicate family members
     *
     * @return array
     */
    private function findDuplicates(int $tenantId, ?string $familyId): array
    {
        $duplicateGroups = [];
        
        // Find groups of duplicates by family_id, first_name, last_name
        // We'll match on name even if date_of_birth differs (since duplicates might have been created with slight differences)
        $query = DB::table('family_members')
            ->select(
                'family_id',
                'first_name',
                'last_name',
                DB::raw('COUNT(*) as count'),
                DB::raw('MIN(created_at) as first_created')
            )
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->when($familyId, function ($q) use ($familyId) {
                return $q->where('family_id', $familyId);
            })
            ->groupBy('family_id', 'first_name', 'last_name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('family_id')
            ->orderBy('first_name')
            ->orderBy('last_name');

        $duplicateGroupsRaw = $query->get();

        if ($duplicateGroupsRaw->isEmpty()) {
            return [];
        }

        $members = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->when($familyId, fn ($q) => $q->where('family_id', $familyId))
            ->where(function ($outer) use ($duplicateGroupsRaw): void {
                foreach ($duplicateGroupsRaw as $group) {
                    $outer->orWhere(function ($q) use ($group): void {
                        $q->where('family_id', $group->family_id)
                            ->where('first_name', $group->first_name)
                            ->where('last_name', $group->last_name);
                    });
                }
            })
            ->orderBy('created_at')
            ->get();

        foreach ($members->groupBy(
            fn (FamilyMember $member) => $member->family_id.'|'.$member->first_name.'|'.$member->last_name
        ) as $groupMembers) {
            if ($groupMembers->count() < 2) {
                continue;
            }

            $duplicateGroups[] = $groupMembers
                ->map(function (FamilyMember $member) {
                    return [
                        'id' => $member->id,
                        'family_id' => $member->family_id,
                        'first_name' => $member->first_name,
                        'last_name' => $member->last_name,
                        'middle_name' => $member->middle_name,
                        'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
                        'phone' => $member->phone,
                        'email' => $member->email,
                        'created_at' => $member->created_at->format('Y-m-d H:i:s'),
                    ];
                })
                ->values()
                ->all();
        }

        return $duplicateGroups;
    }
}

