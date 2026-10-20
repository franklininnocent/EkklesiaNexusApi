<?php

namespace Modules\Donations\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\ContributionDue;

class ContributionBalance
{
    public static function outstandingForDue(object $due): float
    {
        return MoneyMath::toApiNumber(MoneyMath::outstanding($due->amount_due ?? 0, $due->amount_paid ?? 0));
    }

    public static function outstandingString(object $due): string
    {
        return MoneyMath::outstanding($due->amount_due ?? 0, $due->amount_paid ?? 0);
    }

    public static function applyPaid(object $due, string|float|int $amount): string
    {
        $paid = MoneyMath::add($due->amount_paid ?? 0, $amount);
        $dueAmount = MoneyMath::normalize($due->amount_due ?? 0);
        if (MoneyMath::compare($paid, $dueAmount) > 0) {
            $paid = $dueAmount;
        }
        $due->amount_paid = $paid;

        return $paid;
    }

    public static function unwindPaid(object $due, string|float|int $amount): string
    {
        $paid = MoneyMath::floorAtZero(MoneyMath::subtract($due->amount_paid ?? 0, $amount));
        $due->amount_paid = $paid;

        return $paid;
    }

    public static function statusFromPaid(object $due): string
    {
        $current = (string) ($due->status ?? 'pending');
        if (in_array($current, ['waived', 'cancelled'], true)) {
            return $current;
        }

        if (! MoneyMath::isPositive($due->amount_paid ?? 0)) {
            return 'pending';
        }

        if (MoneyMath::compare($due->amount_paid, $due->amount_due ?? 0) >= 0) {
            return 'paid';
        }

        return 'partially_paid';
    }

    /**
     * Raw line outstanding per due (matches sumOutstanding / sumCollectable).
     */
    public static function rawOutstandingSumSqlExpression(): string
    {
        return 'COALESCE(SUM(amount_due - amount_paid), 0)';
    }

    /**
     * Floored line outstanding per due (matches overdue attention aggregates).
     */
    public static function flooredOutstandingSumSqlExpression(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'COALESCE(SUM(GREATEST(amount_due - amount_paid, 0)), 0)'
            : 'COALESCE(SUM(CASE WHEN amount_due > amount_paid THEN amount_due - amount_paid ELSE 0 END), 0)';
    }

    public static function sumOutstanding(Builder $query): float
    {
        $value = $query
            ->whereIn('status', ['pending', 'partially_paid'])
            ->selectRaw(self::rawOutstandingSumSqlExpression().' as outstanding')
            ->value('outstanding');

        return MoneyMath::toApiNumber($value ?? 0);
    }

    public static function sumFlooredOutstanding(Builder $query): float
    {
        $value = $query
            ->whereIn('status', ['pending', 'partially_paid'])
            ->selectRaw(self::flooredOutstandingSumSqlExpression().' as outstanding')
            ->value('outstanding');

        return MoneyMath::toApiNumber($value ?? 0);
    }

    public static function sumCollectable(Builder $query, string $businessDate): float
    {
        return self::sumOutstanding(
            self::scopeCollectable($query, $businessDate)
        );
    }

    public static function scopeCollectable(Builder $query, string $businessDate): Builder
    {
        return $query
            ->whereIn('status', ['pending', 'partially_paid'])
            ->where(function (Builder $inner) use ($businessDate): void {
                $inner->where(function (Builder $started) use ($businessDate): void {
                    $started->whereNotNull('period_start')
                        ->whereDate('period_start', '<=', $businessDate);
                })->orWhere(function (Builder $legacy) use ($businessDate): void {
                    $legacy->whereNull('period_start')
                        ->whereDate('due_date', '<=', $businessDate);
                });
            });
    }

    /**
     * Project installment dues use due_date only (no contribution period columns).
     */
    public static function scopeProjectInstallmentCollectable(Builder $query, string $businessDate): Builder
    {
        return $query
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<=', $businessDate);
    }

    public static function scopeProjectInstallmentRemainingCollectable(Builder $query, string $businessDate): Builder
    {
        return $query
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '>=', $businessDate);
    }

    public static function scopeProjectInstallmentDueNextDays(Builder $query, string $businessDate, int $days): Builder
    {
        $end = Carbon::parse($businessDate)->addDays($days)->toDateString();

        return self::scopeProjectInstallmentRemainingCollectable($query, $businessDate)
            ->whereDate('due_date', '<=', $end);
    }

    public static function scopeProjectInstallmentDueAfterDays(Builder $query, string $businessDate, int $days): Builder
    {
        $after = Carbon::parse($businessDate)->addDays($days)->toDateString();

        return self::scopeProjectInstallmentRemainingCollectable($query, $businessDate)
            ->whereDate('due_date', '>', $after);
    }

    public static function scopeOverdue(Builder $query, string $businessDate): Builder
    {
        return $query
            ->whereIn('status', ['pending', 'partially_paid'])
            ->whereDate('due_date', '<', $businessDate);
    }

    public static function scopeRemainingCollectable(Builder $query, string $businessDate): Builder
    {
        return self::scopeCollectable($query, $businessDate)
            ->whereDate('due_date', '>=', $businessDate);
    }

    /**
     * Collectable dues due on or before business date + N days (inclusive), not yet overdue.
     */
    public static function scopeDueNextDays(Builder $query, string $businessDate, int $days): Builder
    {
        $end = Carbon::parse($businessDate)->addDays($days)->toDateString();

        return self::scopeRemainingCollectable($query, $businessDate)
            ->whereDate('due_date', '<=', $end);
    }

    /**
     * Collectable dues with due date after business date + N days.
     */
    public static function scopeDueAfterDays(Builder $query, string $businessDate, int $days): Builder
    {
        $after = Carbon::parse($businessDate)->addDays($days)->toDateString();

        return self::scopeRemainingCollectable($query, $businessDate)
            ->whereDate('due_date', '>', $after);
    }

    public static function scheduleState(ContributionDue $due, string $businessDate): string
    {
        if (! in_array($due->status, ['pending', 'partially_paid'], true)) {
            return $due->status;
        }

        if (self::outstandingForDue($due) <= 0) {
            return 'paid';
        }

        $dueDate = $due->due_date?->toDateString();
        $periodStart = $due->period_start?->toDateString();
        $periodEnd = $due->period_end?->toDateString();

        if ($periodStart && $periodEnd
            && $businessDate >= $periodStart
            && $businessDate <= $periodEnd) {
            return 'current';
        }

        if ($dueDate && $dueDate < $businessDate) {
            return 'overdue';
        }

        if ($periodEnd && $dueDate
            && $businessDate > $periodEnd
            && $businessDate <= $dueDate) {
            return 'grace';
        }

        if ($dueDate && $dueDate === $businessDate) {
            return 'due_today';
        }

        if ($periodStart && $businessDate < $periodStart) {
            return 'future';
        }

        if ($dueDate && $dueDate > $businessDate) {
            return 'future';
        }

        return 'pending';
    }

    public static function isCollectable(ContributionDue $due, string $businessDate): bool
    {
        if (! in_array($due->status, ['pending', 'partially_paid'], true)) {
            return false;
        }

        if (self::outstandingForDue($due) <= 0) {
            return false;
        }

        $periodStart = $due->period_start?->toDateString();
        if ($periodStart !== null) {
            return $businessDate >= $periodStart;
        }

        $dueDate = $due->due_date?->toDateString();

        return $dueDate !== null && $dueDate <= $businessDate;
    }
}
