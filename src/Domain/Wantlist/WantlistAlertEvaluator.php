<?php
declare(strict_types=1);

namespace App\Domain\Wantlist;

final class WantlistAlertEvaluator
{
    /** Relative-drop floor: new lowest must be at least this fraction below the previous lowest. */
    public const DROP_FRACTION = 0.10;
    /** Absolute-drop floor (account currency): new lowest at least this many units below previous. */
    public const DROP_ABSOLUTE = 5.0;

    /**
     * Decide whether a refresh raises an alert for one want.
     *
     * @param float|null $previousLowest last stored lowest before this refresh (null = first refresh)
     * @param float|null $newLowest      lowest from this refresh (null = none for sale)
     * @param float|null $target         user's target price (null = no target)
     * @param float|null $lastAlertPrice new_price of the latest undismissed alert (null = none active)
     * @return array{reason:string, old_price:float|null, new_price:float}|null
     */
    public function evaluate(?float $previousLowest, ?float $newLowest, ?float $target, ?float $lastAlertPrice): array|null
    {
        if ($newLowest === null) {
            return null;
        }

        $targetHit = $target !== null && $newLowest <= $target;
        $dropHit = $previousLowest !== null
            && ($newLowest <= $previousLowest * (1 - self::DROP_FRACTION)
                || $newLowest <= $previousLowest - self::DROP_ABSOLUTE);

        if (!$targetHit && !$dropHit) {
            return null;
        }

        // De-dup: only re-fire when the price drops strictly below the last alerted price.
        if ($lastAlertPrice !== null && $newLowest >= $lastAlertPrice) {
            return null;
        }

        return [
            'reason' => $targetHit ? 'target' : 'drop',
            'old_price' => $previousLowest,
            'new_price' => $newLowest,
        ];
    }
}
