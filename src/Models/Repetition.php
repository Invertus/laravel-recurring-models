<?php

namespace MohammedManssour\LaravelRecurringModels\Models;

use Carbon\CarbonInterface as Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use MohammedManssour\LaravelRecurringModels\Database\Factories\RepetitionFactory;
use MohammedManssour\LaravelRecurringModels\Enums\RepetitionType;
use MohammedManssour\LaravelRecurringModels\Exceptions\DriverNotSupportedException;
use MohammedManssour\LaravelRecurringModels\Support\RepeatCollection;

/**
 * @model Repetition
 *
 * @property int $id
 * @property \MohammedManssour\LaravelRecurringModels\Enums\RepetitionType $type
 * @property \Carbon\Carbon $start_at
 * @property ?int $interval
 * @property ?\Carbon\Carbon $end_at
 */
class Repetition extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'start_at', 'tz_offset', 'interval', 'end_at',
        'year', 'month', 'day', 'week', 'week_of_month', 'weekday',
        'start_time', 'end_time',
    ];

    protected $casts = [
        'type' => RepetitionType::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'tz_offset' => 'integer',
    ];

    protected static function newFactory()
    {
        return RepetitionFactory::new();
    }

    public function newCollection(array $models = []): RepeatCollection
    {
        return new RepeatCollection($models);
    }

    /*-----------------------------------------------------
    * Relations
    -----------------------------------------------------*/
    public function repeatable(): MorphTo
    {
        return $this->morphTo('repeatable');
    }

    /*-----------------------------------------------------
    * scopes
    -----------------------------------------------------*/
    public function scopeWhereActiveForTheDate(Builder $query, Carbon $date): Builder
    {
        return $query
            ->where('start_at', '<=', $date->toDateTimeString())
            ->where(
                fn($query) => $query
                    ->whereNull('end_at')
                    ->orWhere('end_at', '>=', $date)
            )
            ->where(
                fn($query) => $query
                    ->whereNull('start_time')
                    ->orWhere(fn($query) => $query
                        ->where('start_time', '<=', $date->format('H:i:s'))
                        ->where('end_time', '>=', $date->format('H:i:s'))
                    )
            );
    }

    public function scopeWhereOccurresOn(Builder $query, Carbon $date): Builder
    {
        return $query
            ->WhereActiveForTheDate($date)
            ->where(
                fn ($query) => $query->whereHasSimpleRecurringOn($date)
                    ->orWhere(fn ($query) => $query->whereHasComplexRecurringOn($date))
            );
    }

    public function scopeWhereOccurresBetween(Builder $query, Carbon $start, Carbon $end): Builder
    {
        $dates = CarbonPeriod::create(
            $start,
            $end,
        );

        $query->where(function (Builder $query) use ($dates) {
            foreach ($dates as $date) {
                $query->orWhere(fn ($query) => $query->whereOccurresOn($date));
            }
        });

        return $query;
    }

    public function scopeWhereHasSimpleRecurringOn(Builder $query, Carbon $date): Builder
    {
        $secondsInDay = 86399;
        $timestamp = $date->clone()->utc()->timestamp;

        $query
            ->where('type', RepetitionType::Simple);

        $driver = $query->getConnection()->getConfig('driver');
        match ($driver) {
            'mysql' => $query->whereRaw('(?  - UNIX_TIMESTAMP(`start_at`)) % `interval` BETWEEN 0 AND ?', [$timestamp, $secondsInDay]),
            'sqlite' => $query->whereRaw('(? - unixepoch(`start_at`)) % `interval` BETWEEN 0 AND ?', [$timestamp, $secondsInDay]),
            'pgsql' => $query->whereRaw("MOD((? - DATE_PART('EPOCH', start_at))::INTEGER, interval) BETWEEN 0 AND ?", [$timestamp, $secondsInDay]),
            default => throw new DriverNotSupportedException($driver),
        };

        return $query;
    }

    public function scopeWhereHasComplexRecurringOn(Builder $query, Carbon $date)
    {
        $timestamp = $date->timestamp;
        $driver = $query->getConnection()->getConfig('driver');

        $query->where('type', RepetitionType::Complex);
        if ($driver == 'mysql') {
            return $query->whereRaw("(`year` = '*' or `year` = YEAR(FROM_UNIXTIME(? + `tz_offset`)))", [$timestamp])
                ->whereRaw("(`month` = '*' or `month` = MONTH(FROM_UNIXTIME(? + `tz_offset`)))", [$timestamp])
                ->whereRaw("(`day` = '*' or `day` = DAY(FROM_UNIXTIME(? + `tz_offset`)))", [$timestamp])
                ->whereRaw("(`week` = '*' or `week` = WEEK(FROM_UNIXTIME(? + `tz_offset`)))", [$timestamp])
                ->whereRaw("(`week_of_month` = '*' or `week_of_month` = FLOOR((DAY(FROM_UNIXTIME(? + `tz_offset`)) + 6) / 7))", [$timestamp]) // todo: implement as in pgsql
                ->whereRaw("(`weekday` = '*' or `weekday` = (WEEKDAY(FROM_UNIXTIME(? + `tz_offset`)) + 8) % 7)", [$timestamp]);
        }

        if ($driver == 'sqlite') {
            return $query->whereRaw("(`year` = '*' or `year` = cast(strftime('%Y', DATETIME(? + `tz_offset`, 'unixepoch')) as integer))", [$timestamp])
                ->whereRaw("(`month` = '*' or `month` = cast(strftime('%m', DATETIME(? + `tz_offset`, 'unixepoch')) as integer))", [$timestamp])
                ->whereRaw("(`day` = '*' or `day` = cast(strftime('%d', DATETIME(? + `tz_offset`, 'unixepoch')) as integer))", [$timestamp])
                ->whereRaw("(`week` = '*' or `week` = cast(strftime('%W', DATETIME(? + `tz_offset`, 'unixepoch')) as integer))", [$timestamp])
                ->whereRaw("(`week_of_month` = '*' or `week_of_month` = (cast(strftime('%d', DATETIME(? + `tz_offset`, 'unixepoch')) as integer) + 6) / 7)", [$timestamp]) // todo: implement as in pgsql
                ->whereRaw("(`weekday` = '*' or `weekday` = cast(strftime('%w', DATETIME(? + `tz_offset`, 'unixepoch')) as integer))", [$timestamp]);
        }

        if ($driver == 'pgsql') {
            return $query->whereRaw("(year = '*' or year = extract(year from to_timestamp(? + tz_offset) at time zone 'UTC')::varchar)", [$timestamp])
                ->whereRaw("(month = '*' or month = extract(month from to_timestamp(? + tz_offset) at time zone 'UTC')::varchar)", [$timestamp])
                ->whereRaw("(day = '*' or day = extract(day from to_timestamp(? + tz_offset) at time zone 'UTC')::varchar)", [$timestamp])
                ->whereRaw("(week = '*' or week = extract(week from to_timestamp(? + tz_offset) at time zone 'UTC')::varchar)", [$timestamp])
                ->whereRaw("(week_of_month = '*' or week_of_month = (((date_part('week', to_timestamp(? + tz_offset) at time zone 'UTC')::integer - 1) % 4) + 1)::varchar)", [$timestamp])
                ->whereRaw("(weekday = '*' or weekday = extract(dow from to_timestamp(? + tz_offset) at time zone 'UTC')::varchar)", [$timestamp]);
        }

        throw new DriverNotSupportedException($driver);
    }
}
