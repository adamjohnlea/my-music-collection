<?php
declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Abstraction over usleep() so that time-based backoff/throttle logic can be
 * exercised deterministically (and without real delays) in tests.
 */
interface Sleeper
{
    /**
     * Suspend execution for the given number of microseconds.
     */
    public function usleep(int $microseconds): void;
}
