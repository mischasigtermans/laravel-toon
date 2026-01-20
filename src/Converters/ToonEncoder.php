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

    protected int $indent;

    protected string $delimiter;

    protected array $omitSet;

    protected array $omitKeysSet;

    protected array $keyAliases;

    protected ?string $dateFormat;

    protected ?int $truncateStrings;

    protected ?int $numberPrecision;

    protected bool $omitAll;

    public function __construct(?ArrayFlattener $flattener = null, ?array $config = null)
    {
        $config ??= Config::get('toon', []);

        if (isset($config['escape_style'])) {
            trigger_error('The "escape_style" config option is deprecated and has been removed in v1.0. Strings are now quoted per TOON v3.0 spec.', E_USER_DEPRECATED);
        }

        $maxDepth = (int) ($config['max_flatten_depth'] ?? 3);
        $this->flattener = $flattener ?? new ArrayFlattener($maxDepth);
        $this->minRowsForTable = (int) ($config['min_rows_for_table'] ?? 2);
        $this->indent = (int) ($config['indent'] ?? 2);
        $this->delimiter = (string) ($config['delimiter'] ?? ',');

        $omit = (array) ($config['omit'] ?? []);
        $this->omitAll = in_array('all', $omit, true);
        $this->omitSet = array_flip($omit);

        $omitKeys = (array) ($config['omit_keys'] ?? []);
        $this->omitKeysSet = array_flip($omitKeys);

        $this->keyAliases = (array) ($config['key_aliases'] ?? []);
        $this->dateFormat = $config['date_format'] ?? null;
        $this->truncateStrings = $config['truncate_strings'] ?? null;
        $this->numberPrecision = $config['number_precision'] ?? null;
    }

    protected function shouldOmit(string $type): bool
    {
        return $this->omitAll || isset($this->omitSet[$type]);
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

        return $this->encodePrimitive($input);
    }

    protected function valueToToon(mixed $value, int $depth = 0, ?string $parentKey = null): string
    {
        $indentStr = str_repeat(' ', $this->indent * $depth);

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof \Traversable && ! $value instanceof \DateTimeInterface) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '';
            }

            if (array_is_list($value)) {
                if ($this->flattener->hasNestedObjects($value)) {
                    $flattened = $this->flattener->flatten($value);

                    return $this->flattenedToToon($flattened, $depth, $parentKey);
                }

                if ($this->isArrayOfUniformObjects($value)) {
                    return $this->arrayOfObjectsToToon($value, $depth, $parentKey);
                }

                if ($this->isArrayOfPrimitives($value)) {
                    return $this->inlinePrimitiveArrayToToon($value, $depth, $parentKey);
                }

                return $this->sequentialArrayToToon($value, $depth);
            }

            return $this->associativeArrayToToon($value, $depth);
        }

        return $indentStr.$this->encodePrimitive($value);
    }

    protected function flattenedToToon(array $flattened, int $depth, ?string $parentKey = null): string
    {
        $indentStr = str_repeat(' ', $this->indent * $depth);
        $rowIndent = str_repeat(' ', $this->indent * ($depth + 1));
        $columns = array_map(fn ($col) => $this->encodeKey($col), $flattened['columns']);
        $rows = $flattened['rows'];

        $keyPart = $parentKey !== null ? $this->encodeKey($parentKey) : 'items';
        $header = $indentStr.$keyPart.'['.$this->getArrayLengthMarker(count($rows)).']{'.implode($this->delimiter, $columns).'}:';

        $rowLines = array_map(
            fn (array $row) => $rowIndent.implode($this->delimiter, array_map(fn ($v) => $this->encodeTableValue($v), $row)),
            $rows
        );

        return $header."\n".implode("\n", $rowLines);
    }

    protected function arrayOfObjectsToToon(array $arr, int $depth, ?string $parentKey = null): string
    {
        $indentStr = str_repeat(' ', $this->indent * $depth);
        $rowIndent = str_repeat(' ', $this->indent * ($depth + 1));

        $keyPart = $parentKey !== null ? $this->encodeKey($parentKey) : 'items';

        if (empty($arr)) {
            return $indentStr.$keyPart.'[0]{}:';
        }

        $fields = array_keys((array) $arr[0]);
        $formattedFields = array_map(fn ($f) => $this->encodeKey($f), $fields);

        $header = $indentStr.$keyPart.'['.$this->getArrayLengthMarker(count($arr)).']{'.implode($this->delimiter, $formattedFields).'}:';

        $rows = [];
        foreach ($arr as $item) {
            $cells = array_map(fn ($f) => $this->encodeTableValue($item[$f] ?? null), $fields);
            $rows[] = $rowIndent.implode($this->delimiter, $cells);
        }

        return $header."\n".implode("\n", $rows);
    }

    protected function inlinePrimitiveArrayToToon(array $arr, int $depth, ?string $parentKey = null): string
    {
        $indentStr = str_repeat(' ', $this->indent * $depth);
        $keyPart = $parentKey !== null ? $this->encodeKey($parentKey) : 'items';

        $values = array_map(fn ($v) => $this->encodePrimitive($v), $arr);

        return $indentStr.$keyPart.'['.$this->getArrayLengthMarker(count($arr)).']: '.implode($this->delimiter, $values);
    }

    protected function isArrayOfPrimitives(array $arr): bool
    {
        foreach ($arr as $item) {
            if (! $this->isScalar($item)) {
                return false;
            }
        }

        return true;
    }

    protected function sequentialArrayToToon(array $arr, int $depth): string
    {
        $indentStr = str_repeat(' ', $this->indent * $depth);
        $lines = [];

        foreach ($arr as $item) {
            if ($this->isScalar($item)) {
                $lines[] = $indentStr.$this->encodePrimitive($item);
            } else {
                $lines[] = $this->valueToToon($item, $depth);
            }
        }

        return implode("\n", $lines);
    }

    protected function associativeArrayToToon(array $arr, int $depth): string
    {
        $indentStr = str_repeat(' ', $this->indent * $depth);
        $lines = [];

        foreach ($arr as $key => $val) {
            if (isset($this->omitKeysSet[$key])) {
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

            $formattedKey = $this->encodeKey((string) $key);

            if ($this->isScalar($val)) {
                $lines[] = $indentStr.$formattedKey.': '.$this->encodePrimitive($val);
            } elseif (is_array($val) && empty($val)) {
                $lines[] = $indentStr.$formattedKey.':';
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfPrimitives($val)) {
                $lines[] = $this->inlinePrimitiveArrayToToon($val, $depth, (string) $key);
            } elseif (is_array($val) && array_is_list($val) && $this->flattener->hasNestedObjects($val)) {
                $flattened = $this->flattener->flatten($val);
                $lines[] = $this->flattenedToToon($flattened, $depth, (string) $key);
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfUniformObjects($val)) {
                $lines[] = $this->arrayOfObjectsToToon($val, $depth, (string) $key);
            } else {
                $lines[] = $indentStr.$formattedKey.':';
                $lines[] = $this->valueToToon($val, $depth + 1);
            }
        }

        return implode("\n", $lines);
    }

    protected function encodePrimitive(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if ($v instanceof DateTimeInterface) {
            $formatted = $this->dateFormat !== null
                ? $v->format($this->dateFormat)
                : $v->format('Y-m-d\TH:i:sP');

            return $this->encodeString($formatted);
        }

        if (is_float($v)) {
            return $this->encodeNumber($v);
        }

        if (is_int($v)) {
            return (string) $v;
        }

        if (is_array($v)) {
            return json_encode($v) ?: '[]';
        }

        return $this->encodeString((string) $v);
    }

    protected function encodeTableValue(mixed $v): string
    {
        if ($v === null) {
            return '';
        }

        return $this->encodePrimitive($v);
    }

    protected function encodeNumber(float $v): string
    {
        if (is_nan($v) || is_infinite($v)) {
            return 'null';
        }

        if ($v === -0.0) {
            $v = 0.0;
        }

        if ($this->numberPrecision !== null) {
            return number_format($v, $this->numberPrecision, '.', '');
        }

        if (floor($v) === $v && abs($v) < 1e15) {
            return number_format($v, 0, '', '');
        }

        $result = rtrim(rtrim(sprintf('%.16g', $v), '0'), '.');

        if (str_contains($result, 'E') || str_contains($result, 'e')) {
            if (abs($v) >= 1) {
                $result = number_format($v, 0, '', '');
            } else {
                $result = rtrim(rtrim(sprintf('%.16f', $v), '0'), '.');
            }
        }

        return $result;
    }

    protected function encodeString(string $s): string
    {
        if ($this->dateFormat !== null && $this->looksLikeIsoDate($s)) {
            $s = Carbon::parse($s)->format($this->dateFormat);
        }

        if ($this->truncateStrings !== null && strlen($s) > $this->truncateStrings) {
            $s = substr($s, 0, $this->truncateStrings).'...';
        }

        if ($this->needsQuoting($s)) {
            return $this->quoteString($s);
        }

        return $s;
    }

    protected function needsQuoting(string $s): bool
    {
        if ($s === '') {
            return true;
        }

        $first = $s[0];
        $last = $s[strlen($s) - 1];

        if ($first === ' ' || $first === "\t" || $last === ' ' || $last === "\t") {
            return true;
        }

        if ($s === 'true' || $s === 'false' || $s === 'null') {
            return true;
        }

        if ($s === '-' || ($first === '-' && isset($s[1]) && $s[1] === ' ')) {
            return true;
        }

        if ($first === '-' || $first === '.' || ctype_digit($first)) {
            if ($this->looksLikeNumber($s)) {
                return true;
            }
        }

        if (preg_match('/[:"\\\\,\[\]{}\x00-\x1f]/', $s)) {
            return true;
        }

        if ($this->delimiter !== ',' && str_contains($s, $this->delimiter)) {
            return true;
        }

        return false;
    }

    protected function looksLikeNumber(string $s): bool
    {
        $len = strlen($s);
        if ($len === 0) {
            return false;
        }

        $first = $s[0];
        if ($first === '0' && $len > 1 && ctype_digit($s[1])) {
            return true;
        }

        return (bool) preg_match('/^-?\d+(?:\.\d+)?(?:e[+-]?\d+)?$/i', $s);
    }

    protected function quoteString(string $s): string
    {
        $escaped = strtr($s, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);

        return '"'.$escaped.'"';
    }

    protected function encodeKey(string $key): string
    {
        $key = $this->keyAliases[$key] ?? $key;

        if ($this->keyNeedsQuoting($key)) {
            return $this->quoteString($key);
        }

        return $key;
    }

    protected function keyNeedsQuoting(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
            return true;
        }

        return false;
    }

    protected function getDelimiterKey(): string
    {
        return match ($this->delimiter) {
            "\t" => '\t',
            '|' => '|',
            default => '',
        };
    }

    protected function getArrayLengthMarker(int $count): string
    {
        $delimiterKey = $this->getDelimiterKey();

        return $count.$delimiterKey;
    }

    protected function looksLikeIsoDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}([T\s]\d{2}:\d{2}(:\d{2})?)?/', $s);
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
