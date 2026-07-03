<?php
declare(strict_types=1);

namespace App\Domain\Theme;

/**
 * Single source of truth for themeable design tokens and presets.
 * Derived tokens (--accent-hover, --accent-ink, --header-bg, --wash,
 * --raised-bg, --skeleton-bg) are intentionally NOT editable — they are
 * computed in the CSS baseline and follow their inputs.
 */
final class ThemeRegistry
{
    /**
     * Editable tokens by section. `dark` is the baseline dark value.
     * (A `light` value is added in Phase 2.)
     *
     * @return array<string, list<array{key:string,label:string,dark:string}>>
     */
    public static function groups(): array
    {
        return [
            'Surfaces' => [
                ['key' => '--bg',            'label' => 'Page background', 'dark' => '#0b0b0c'],
                ['key' => '--card',          'label' => 'Card',            'dark' => '#16171a'],
                ['key' => '--card-2',        'label' => 'Card (raised)',   'dark' => '#191b1f'],
                ['key' => '--input-bg',      'label' => 'Input / inset',   'dark' => '#101114'],
                ['key' => '--hover-surface', 'label' => 'Hover surface',   'dark' => '#1d1f24'],
                ['key' => '--btn-bg',        'label' => 'Button',          'dark' => '#1f2937'],
                ['key' => '--btn-bg-hover',  'label' => 'Button hover',    'dark' => '#232f41'],
            ],
            'Text' => [
                ['key' => '--text',    'label' => 'Text',           'dark' => '#e7e7ea'],
                ['key' => '--muted',   'label' => 'Muted text',     'dark' => '#a0a3aa'],
                ['key' => '--faint',   'label' => 'Faint text',     'dark' => '#6c6f77'],
                ['key' => '--btn-ink', 'label' => 'Button text',    'dark' => '#ffffff'],
            ],
            'Accent' => [
                ['key' => '--accent', 'label' => 'Accent', 'dark' => '#67e8f9'],
            ],
            'Borders' => [
                ['key' => '--border',        'label' => 'Border',        'dark' => '#2a2b2f'],
                ['key' => '--border-soft',   'label' => 'Border (soft)', 'dark' => '#212226'],
                ['key' => '--raised-border', 'label' => 'Raised border', 'dark' => '#3a3d44'],
            ],
            'Status' => [
                ['key' => '--up',     'label' => 'Positive / up',   'dark' => '#34d399'],
                ['key' => '--down',   'label' => 'Negative / down', 'dark' => '#f87171'],
                ['key' => '--warn',   'label' => 'Warning',         'dark' => '#e0a458'],
                ['key' => '--danger', 'label' => 'Danger',          'dark' => '#ff4444'],
            ],
        ];
    }

    /** @return list<string> */
    public static function editableKeys(): array
    {
        $keys = [];
        foreach (self::groups() as $tokens) {
            foreach ($tokens as $t) {
                $keys[] = $t['key'];
            }
        }
        return $keys;
    }

    /** @return array<string,string> */
    public static function darkDefaults(): array
    {
        $out = [];
        foreach (self::groups() as $tokens) {
            foreach ($tokens as $t) {
                $out[$t['key']] = $t['dark'];
            }
        }
        return $out;
    }

    /** @return list<array{name:string,mode:string,tokens:array<string,string>}> */
    public static function presets(): array
    {
        return [
            ['name' => 'Console', 'mode' => 'dark', 'tokens' => self::darkDefaults()],
            ['name' => 'Magenta', 'mode' => 'dark', 'tokens' => ['--accent' => '#f472b6']],
            ['name' => 'Amber',   'mode' => 'dark', 'tokens' => ['--accent' => '#fbbf24']],
            ['name' => 'Emerald', 'mode' => 'dark', 'tokens' => ['--accent' => '#34d399']],
        ];
    }
}
