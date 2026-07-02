<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Sleeper;

/**
 * Test Sleeper that records requested durations instead of sleeping, so
 * backoff/throttle behaviour can be asserted deterministically and instantly.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var array<int, int> Microsecond durations passed to usleep(), in order. */
    public array $sleeps = [];

    public function usleep(int $microseconds): void
    {
        $this->sleeps[] = $microseconds;
    }

    /** Number of times a sleep was requested. */
    public function count(): int
    {
        return count($this->sleeps);
    }

    /** Total microseconds that would have been slept. */
    public function total(): int
    {
        return array_sum($this->sleeps);
    }
}
