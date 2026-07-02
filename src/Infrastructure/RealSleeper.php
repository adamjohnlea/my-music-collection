<?php
declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Production Sleeper backed by the native usleep().
 */
final class RealSleeper implements Sleeper
{
    public function usleep(int $microseconds): void
    {
        \usleep($microseconds);
    }
}
