<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FinancialHistoryDateRange
{
    private const TIME_ZONE = 'Asia/Tehran';

    /**
     * @return array{from: CarbonImmutable|null, to: CarbonImmutable|null}
     */
    public static function fromRequest(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ]);
        $from = $validated['from'] ?? null;
        $to = $validated['to'] ?? null;

        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages([
                'to' => 'تاریخ پایان بازه باید هم‌زمان یا پس از تاریخ شروع باشد.',
            ]);
        }

        return [
            'from' => $from === null
                ? null
                : CarbonImmutable::parse($from, self::TIME_ZONE)->startOfDay()->utc(),
            'to' => $to === null
                ? null
                : CarbonImmutable::parse($to, self::TIME_ZONE)->endOfDay()->utc(),
        ];
    }

    /**
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     * @param  array{from: CarbonImmutable|null, to: CarbonImmutable|null}  $range
     */
    public static function apply(
        EloquentBuilder|QueryBuilder $query,
        array $range,
        string $column = 'created_at',
    ): void {
        if ($range['from'] !== null) {
            $query->where($column, '>=', $range['from']);
        }

        if ($range['to'] !== null) {
            $query->where($column, '<=', $range['to']);
        }
    }
}
