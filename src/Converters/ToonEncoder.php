<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Converters;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use MischaSigtermans\Toon\Support\ArrayFlattener;

class ToonEncoder
{
    protected ArrayFlattener $flattener;

    protected int $minRowsForTable;

    protected string $escapeStyle;

    protected array $omit;

    protected array $omitKeys;

    protected array $keyAliases;

    protected ?string $dateFormat;

    protected ?int $truncateStrings;

    protected ?int $numberPrecision;

    public function __construct(?ArrayFlattener $flattener = null)
    {
        $maxDepth = (int) Config::get('toon.max_flatten_depth', 3);
        $this->flattener = $flattener ?? new ArrayFlattener($maxDepth);
        $this->minRowsForTable = (int) Config::get('toon.min_rows_for_table', 2);
        $this->escapeStyle = (string) Config::get('toon.escape_style', 'backslash');
        $this->omit = (array) Config::get('toon.omit', []);
        $this->omitKeys = (array) Config::get('toon.omit_keys', []);
        $this->keyAliases = (array) Config::get('toon.key_aliases', []);
        $this->dateFormat = Config::get('toon.date_format');
        $this->truncateStrings = Config::get('toon.truncate_strings');
        $this->numberPrecision = Config::get('toon.number_precision');
    }

    protected function shouldOmit(string $type): bool
    {
        return in_array('all', $this->omit, true)
            || in_array($type, $this->omit, true);
    }

    public function encode(mixed $input): string
    {
        if (is_string($input) && $this->looksLikeJson($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->valueToToon($decoded);
            }
        }

        if (is_object($input)) {
            $input = json_decode(json_encode($input), true);
        }

        if (is_array($input)) {
            return $this->valueToToon($input);
        }

        return $this->escapeScalar($input);
    }

    protected function valueToToon(mixed $value, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof \Traversable && ! $value instanceof \DateTimeInterface) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            if ($this->isSequentialArray($value)) {
                if ($this->flattener->hasNestedObjects($value)) {
                    $flattened = $this->flattener->flatten($value);

                    return $this->flattenedToToon($flattened, $depth);
                }

                if ($this->isArrayOfUniformObjects($value)) {
                    return $this->arrayOfObjectsToToon($value, $depth);
                }

                return $this->sequentialArrayToToon($value, $depth);
            }

            return $this->associativeArrayToToon($value, $depth);
        }

        return $indent.$this->escapeScalar($value);
    }

    /**
     * @param  array{columns: array<string>, rows: array<array<mixed>>}  $flattened
     */
    protected function flattenedToToon(array $flattened, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $columns = array_map(fn ($col) => $this->formatKey($col), $flattened['columns']);
        $rows = $flattened['rows'];

        $header = $indent.'items['.count($rows).']{'.implode(',', $columns).'}:';

        $rowLines = array_map(
            fn (array $row) => $indent.'  '.implode(',', array_map([$this, 'escapeScalar'], $row)),
            $rows
        );

        return $header."\n".implode("\n", $rowLines);
    }

    protected function arrayOfObjectsToToon(array $arr, int $depth): string
    {
        if (empty($arr)) {
            return str_repeat('  ', $depth).'items[0]{}:';
        }

        $fields = array_keys((array) $arr[0]);
        $formattedFields = array_map(fn ($f) => $this->formatKey($f), $fields);
        $indent = str_repeat('  ', $depth);

        $header = $indent.'items['.count($arr).']{'.implode(',', $formattedFields).'}:';

        $rows = [];
        foreach ($arr as $item) {
            $cells = array_map(fn ($f) => $this->escapeScalar($item[$f] ?? null), $fields);
            $rows[] = $indent.'  '.implode(',', $cells);
        }

        return $header."\n".implode("\n", $rows);
    }

    protected function sequentialArrayToToon(array $arr, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $lines = [];

        foreach ($arr as $item) {
            if ($this->isScalar($item)) {
                $lines[] = $indent.$this->escapeScalar($item);
            } else {
                $lines[] = $this->valueToToon($item, $depth);
            }
        }

        return implode("\n", $lines);
    }

    protected function associativeArrayToToon(array $arr, int $depth): string
    {
        $indent = str_repeat('  ', $depth);
        $lines = [];

        foreach ($arr as $key => $val) {
            if (in_array($key, $this->omitKeys, true)) {
                continue;
            }

            if ($this->shouldOmit('null') && $val === null) {
                continue;
            }

            if ($this->shouldOmit('empty') && $val === '') {
                continue;
            }

            if ($this->shouldOmit('false') && $val === false) {
                continue;
            }

            $formattedKey = $this->formatKey((string) $key);

            if ($this->isScalar($val)) {
                $lines[] = $indent.$formattedKey.': '.$this->escapeScalar($val);
            } else {
                $lines[] = $indent.$formattedKey.':';
                $lines[] = $this->valueToToon($val, $depth + 1);
            }
        }

        return implode("\n", $lines);
    }

    protected function escapeScalar(mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        // Handle DateTime objects
        if ($v instanceof DateTimeInterface) {
            if ($this->dateFormat !== null) {
                return $v->format($this->dateFormat);
            }

            return $v->format('Y-m-d\TH:i:sP');
        }

        // Handle number precision for floats
        if (is_float($v)) {
            if ($this->numberPrecision !== null) {
                return number_format($v, $this->numberPrecision, '.', '');
            }

            return (string) $v;
        }

        if (is_int($v)) {
            return (string) $v;
        }

        if (is_array($v)) {
            return json_encode($v) ?: '[]';
        }

        $s = (string) $v;

        // Format ISO date strings if date_format is set
        if ($this->dateFormat !== null && $this->looksLikeIsoDate($s)) {
            try {
                return Carbon::parse($s)->format($this->dateFormat);
            } catch (\Exception) {
                // If parsing fails, continue with normal string processing
            }
        }

        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');

        if ($this->escapeStyle === 'backslash') {
            $s = str_replace('\\', '\\\\', $s);
            $s = str_replace(',', '\\,', $s);
            $s = str_replace(':', '\\:', $s);
            $s = str_replace("\n", '\\n', $s);
        }

        // Truncate strings if configured
        if ($this->truncateStrings !== null && strlen($s) > $this->truncateStrings) {
            $s = substr($s, 0, $this->truncateStrings).'...';
        }

        return $s;
    }

    protected function looksLikeIsoDate(string $s): bool
    {
        // Match common ISO 8601 date formats:
        // 2024-01-15, 2024-01-15T14:30:00, 2024-01-15 14:30:00, etc.
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}([T\s]\d{2}:\d{2}(:\d{2})?)?/', $s);
    }

    protected function safeKey(string $k): string
    {
        return preg_replace('/[^A-Za-z0-9_\-\.]/', '', $k) ?? $k;
    }

    protected function formatKey(string $key): string
    {
        return $this->keyAliases[$key] ?? $this->safeKey($key);
    }

    protected function isScalar(mixed $v): bool
    {
        return is_null($v) || is_scalar($v) || $v instanceof DateTimeInterface;
    }

    protected function looksLikeJson(string $s): bool
    {
        $s = trim($s);

        return $s !== '' && (str_starts_with($s, '{') || str_starts_with($s, '['));
    }

    protected function isSequentialArray(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }

    protected function isArrayOfUniformObjects(array $arr): bool
    {
        if (count($arr) < $this->minRowsForTable) {
            return false;
        }

        $firstKeys = null;

        foreach ($arr as $item) {
            if (! is_array($item)) {
                return false;
            }

            $keys = array_keys($item);

            if ($firstKeys === null) {
                $firstKeys = $keys;
            } elseif ($keys !== $firstKeys) {
                return false;
            }
        }

        return true;
    }
}
