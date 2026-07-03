<?php
declare(strict_types=1);

namespace App\Domain\Theme;

use App\Infrastructure\KvStore;

final class ThemeService
{
    private const KEY = 'theme';
    private const MODES = ['dark', 'light'];

    public function __construct(private readonly KvStore $kv)
    {
    }

    /** @return array{mode:string, overrides:array<string,string>} */
    public function current(): array
    {
        $raw = $this->kv->get(self::KEY);
        if ($raw === null || $raw === '') {
            return ['mode' => 'dark', 'overrides' => []];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['mode' => 'dark', 'overrides' => []];
        }
        $mode = (is_string($data['mode'] ?? null) && in_array($data['mode'], self::MODES, true))
            ? $data['mode'] : 'dark';
        $overrides = [];
        $editable = ThemeRegistry::editableKeys();
        if (is_array($data['overrides'] ?? null)) {
            foreach ($data['overrides'] as $k => $v) {
                if (is_string($k) && is_string($v)
                    && in_array($k, $editable, true) && self::isValidColor($v)) {
                    $overrides[$k] = $v;
                }
            }
        }
        return ['mode' => $mode, 'overrides' => $overrides];
    }

    /**
     * @param array<string,string> $overrides
     * @throws \InvalidArgumentException
     */
    public function save(string $mode, array $overrides): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Invalid mode: {$mode}");
        }
        $editable = ThemeRegistry::editableKeys();
        $clean = [];
        foreach ($overrides as $k => $v) {
            if (!in_array($k, $editable, true)) {
                throw new \InvalidArgumentException("Unknown token: {$k}");
            }
            if (!self::isValidColor($v)) {
                throw new \InvalidArgumentException("Invalid colour for {$k}: {$v}");
            }
            $clean[$k] = $v;
        }
        $this->kv->set(self::KEY, (string)json_encode(['mode' => $mode, 'overrides' => $clean]));
    }

    public function reset(): void
    {
        $mode = $this->current()['mode'];
        $this->kv->set(self::KEY, (string)json_encode(['mode' => $mode, 'overrides' => []]));
    }

    /** @return array{mode:string, dark:array<string,string>, overrides:array<string,string>} */
    public function forView(): array
    {
        $current = $this->current();
        return [
            'mode' => $current['mode'],
            'dark' => ThemeRegistry::darkDefaults(),
            'overrides' => $current['overrides'],
        ];
    }

    public static function isValidColor(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1) {
            return true;
        }
        return preg_match('/^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)$/i', $value) === 1;
    }
}
