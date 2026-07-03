<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

use DateTimeImmutable;

final class SnapshotChart
{
    /**
     * Build a chart model from value snapshots for the "value over time" card.
     *
     * X is spaced by real `captured_at` timestamps; Y is zoomed to the value
     * [min, max] range with ~8% vertical padding so small real changes are visible.
     *
     * @param array<int, array{total_value: float|string, captured_at: string}> $snapshots ordered by captured_at ASC
     * @param int $width  SVG viewport width in user units
     * @param int $height SVG viewport height in user units
     * @return array<string, mixed> chart model — see the plan/spec for the key contract
     */
    public static function build(array $snapshots, int $width, int $height): array
    {
        $n = count($snapshots);

        if ($n === 0) {
            return self::emptyModel();
        }

        $values = array_map(static fn($s): float => (float) $s['total_value'], $snapshots);
        $times  = array_map(static fn($s): int => (new DateTimeImmutable($s['captured_at']))->getTimestamp(), $snapshots);
        $dates  = array_map(static fn($s): string => (new DateTimeImmutable($s['captured_at']))->format('j M Y'), $snapshots);

        $startValue   = $values[0];
        $currentValue = $values[$n - 1];
        $deltaAbs     = $currentValue - $startValue;
        $deltaPct     = $startValue > 0.0 ? ($deltaAbs / $startValue) * 100.0 : null;
        $direction    = $deltaAbs > 0.0 ? 'up' : ($deltaAbs < 0.0 ? 'down' : 'flat');

        $model = [
            'state'     => $n === 1 ? 'single' : 'series',
            'linePoints' => '',
            'areaPoints' => '',
            'dots'      => [],
            'current'   => ['value' => $currentValue, 'date' => $dates[$n - 1]],
            'start'     => ['value' => $startValue, 'date' => $dates[0]],
            'deltaAbs'  => $deltaAbs,
            'deltaPct'  => $deltaPct,
            'direction' => $direction,
            'axis'      => [
                'startDate'   => $dates[0],
                'currentDate' => $dates[$n - 1],
                'minValue'    => min($values),
                'maxValue'    => max($values),
            ],
        ];

        if ($n === 1) {
            return $model;
        }

        $pad   = (int) round($height * 0.08);
        $min   = min($values);
        $max   = max($values);
        $vSpan = $max - $min;
        $minT  = $times[0];
        $maxT  = $times[$n - 1];
        $tSpan = $maxT - $minT;

        $points = [];
        foreach ($values as $i => $v) {
            // Same-timestamp degenerate case: fall back to index spacing.
            $x = $tSpan > 0
                ? (int) round(($times[$i] - $minT) / $tSpan * $width)
                : (int) round($i * $width / ($n - 1));
            // Flat series (all equal): centre vertically.
            $y = $vSpan > 0
                ? $pad + (int) round(($max - $v) / $vSpan * ($height - 2 * $pad))
                : (int) round($height / 2);
            $points[] = $x . ',' . $y;
            $model['dots'][] = ['x' => $x, 'y' => $y, 'value' => $v, 'date' => $dates[$i]];
        }

        $model['linePoints'] = implode(' ', $points);
        $model['areaPoints'] = implode(' ', $points) . ' ' . $width . ',' . $height . ' 0,' . $height;

        return $model;
    }

    /** @return array<string, mixed> */
    private static function emptyModel(): array
    {
        return [
            'state'      => 'empty',
            'linePoints' => '',
            'areaPoints' => '',
            'dots'       => [],
            'current'    => null,
            'start'      => null,
            'deltaAbs'   => 0.0,
            'deltaPct'   => null,
            'direction'  => 'flat',
            'axis'       => ['startDate' => '', 'currentDate' => '', 'minValue' => 0.0, 'maxValue' => 0.0],
        ];
    }
}
