<?php

namespace App\Presentation\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class DiscogsFilters extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('strip_discogs_suffix', [$this, 'stripDiscogsSuffix']),
        ];
    }

    /**
     * Strip trailing numeric Discogs disambiguation suffix like "Artist Name (2)" -> "Artist Name".
     * Only strips when the pattern is exactly a space + (digits) at the end of the string.
     */
    public function stripDiscogsSuffix(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return $name;
        }
        return (string) preg_replace('/\s\(\d+\)$/', '', $name);
    }
}
