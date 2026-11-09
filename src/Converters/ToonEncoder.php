<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Converters;

use BackedEnum;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Config;
use JsonSerializable;
use stdClass;
use Stringable;
use Traversable;

class ToonEncoder
{
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

    protected array $indentCache = [];

    public function __construct(?array $config = null)
    {
        $config ??= Config::get('toon', []);

        $this->minRowsForTable = max(1, (int) ($config['min_rows_for_table'] ?? 1));
        $this->indent = (int) ($config['indent'] ?? 2);
        $this->delimiter = (string) ($config['delimiter'] ?? ',');

        $omit = (array) ($config['omit'] ?? []);
        $this->omitAll = in_array('all', $omit, true);
        $this->omitSet = array_flip($omit);
        $this->omitKeysSet = array_flip((array) ($config['omit_keys'] ?? []));

        $this->keyAliases = (array) ($config['key_aliases'] ?? []);
        $this->dateFormat = $config['date_format'] ?? null;
        $this->truncateStrings = $config['truncate_strings'] ?? null;
        $this->numberPrecision = $config['number_precision'] ?? null;
    }

    public function encode(mixed $input): string
    {
        if (is_string($input) && $this->looksLikeJson($input)) {
            $decoded = json_decode($input, false);
            if (json_last_error() === JSON_ERROR_NONE && (is_array($decoded) || is_object($decoded))) {
                $input = $decoded;
            }
        }

        $value = $this->normalize($input);

        if ($this->isPrimitive($value)) {
            return $this->encodePrimitive($value);
        }

        if ($value instanceof stdClass) {
            return '';
        }

        if (array_is_list($value)) {
            return $value === []
                ? '[]'
                : implode("\n", $this->arrayLines('', $value, 0));
        }

        $shape = $this->keyedShape($value);
        if ($shape !== null) {
            return implode("\n", $this->keyedLines('', $value, $shape, 0));
        }

        return implode("\n", $this->objectLines($value, 0));
    }

    /**
     * Normalize host values into the JSON model: lists and associative arrays,
     * with an empty object kept as stdClass so it stays distinct from an empty array.
     */
    protected function normalize(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof stdClass) {
            $value = (array) $value;

            if ($value === []) {
                return new stdClass;
            }

            return $this->normalizeObject($value);
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof Traversable) {
            $value = iterator_to_array($value);
        } elseif ($value instanceof JsonSerializable) {
            $value = $value->jsonSerialize();
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? array_map(fn ($v) => $this->normalize($v), $value)
                : $this->normalizeObject($value);
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return is_object($value) ? null : $value;
    }

    protected function normalizeObject(array $object): array|stdClass
    {
        $result = [];

        foreach ($object as $key => $val) {
            if (! isset($this->omitKeysSet[$key])) {
                $result[$key] = $this->normalize($val);
            }
        }

        return $result === [] ? new stdClass : $result;
    }

    protected function objectLines(array $object, int $depth): array
    {
        $lines = [];

        foreach ($object as $key => $val) {
            if (! $this->shouldOmitValue($val)) {
                array_push($lines, ...$this->fieldLines((string) $key, $val, $depth));
            }
        }

        return $lines;
    }

    protected function fieldLines(string $key, mixed $val, int $depth): array
    {
        $indent = $this->indentStr($depth);
        $encodedKey = $this->encodeKey($key);

        if ($this->isPrimitive($val)) {
            return [$indent.$encodedKey.': '.$this->encodePrimitive($val)];
        }

        if ($val instanceof stdClass) {
            return [$indent.$encodedKey.':'];
        }

        if (array_is_list($val)) {
            return $val === []
                ? [$indent.$encodedKey.': []']
                : $this->arrayLines($encodedKey, $val, $depth);
        }

        $shape = $this->keyedShape($val);
        if ($shape !== null) {
            return $this->keyedLines($encodedKey, $val, $shape, $depth);
        }

        return [$indent.$encodedKey.':', ...$this->objectLines($val, $depth + 1)];
    }

    /**
     * Lines for a non-empty array whose header is `$encodedKey[N]`, or keyless when the key is ''.
     * A keyless header carries a field list only at the document root.
     */
    protected function arrayLines(string $encodedKey, array $items, int $depth, bool $inListItem = false): array
    {
        $indent = $this->indentStr($depth);
        $head = $indent.($inListItem ? '- ' : '').$encodedKey.'['.count($items).$this->delimiterSymbol();

        if ($this->allPrimitive($items)) {
            return [$head.']: '.$this->joinCells($items)];
        }

        if (! $inListItem && count($items) >= $this->minRowsForTable) {
            $shape = $this->tabularShape($items);

            if ($shape !== null) {
                $rowIndent = $this->indentStr($depth + 1);
                $lines = [$head.']{'.$this->encodeFields($shape).'}:'];

                foreach ($items as $item) {
                    $lines[] = $rowIndent.$this->joinCells($this->leafValues($item, $shape));
                }

                return $lines;
            }
        }

        $lines = [$head.']:'];

        foreach ($items as $item) {
            array_push($lines, ...$this->listItemLines($item, $depth + 1));
        }

        return $lines;
    }

    protected function listItemLines(mixed $item, int $depth): array
    {
        $indent = $this->indentStr($depth);

        if ($this->isPrimitive($item)) {
            return [$indent.'- '.$this->encodePrimitive($item)];
        }

        if ($item instanceof stdClass) {
            return [$indent.'-'];
        }

        if (array_is_list($item)) {
            return $item === []
                ? [$indent.'- [0'.$this->delimiterSymbol().']:']
                : $this->arrayLines('', $item, $depth, inListItem: true);
        }

        // Fields render one level deeper; the first field then moves onto the hyphen line.
        $lines = $this->objectLines($item, $depth + 1);

        if ($lines === []) {
            return [$indent.'-'];
        }

        $lines[0] = $indent.'- '.ltrim($lines[0], ' ');

        return $lines;
    }

    protected function keyedLines(string $encodedKey, array $object, array $shape, int $depth): array
    {
        $rowIndent = $this->indentStr($depth + 1);
        $lines = [$this->indentStr($depth).$encodedKey.'['.count($object).':'.$this->delimiterSymbol().']{'.$this->encodeFields($shape).'}:'];

        foreach ($object as $key => $val) {
            $lines[] = $rowIndent.$this->encodeKey((string) $key).': '.$this->joinCells($this->leafValues($val, $shape));
        }

        return $lines;
    }

    /**
     * The shared field tree of uniform objects, as [name => subtree|null], or null when they are not uniform.
     */
    protected function tabularShape(array $objects): ?array
    {
        $first = $objects[0] ?? null;

        if (! is_array($first) || $first === [] || array_is_list($first)) {
            return null;
        }

        $keys = array_map('strval', array_keys($first));
        $sortedKeys = $keys;
        sort($sortedKeys);

        foreach ($objects as $object) {
            if (! is_array($object) || $object === [] || array_is_list($object)) {
                return null;
            }

            $objectKeys = array_map('strval', array_keys($object));
            sort($objectKeys);

            if ($objectKeys !== $sortedKeys) {
                return null;
            }
        }

        $shape = [];

        foreach ($keys as $key) {
            $column = array_column($objects, $key);

            if ($this->allPrimitive($column)) {
                $shape[$key] = null;

                continue;
            }

            $subShape = $this->tabularShape($column);

            if ($subShape === null) {
                return null;
            }

            $shape[$key] = $subShape;
        }

        return $shape;
    }

    protected function keyedShape(array $object): ?array
    {
        if (count($object) < max(2, $this->minRowsForTable)) {
            return null;
        }

        return $this->tabularShape(array_values($object));
    }

    protected function encodeFields(array $shape): string
    {
        $fields = [];

        foreach ($shape as $name => $subShape) {
            $fields[] = $this->encodeKey((string) $name).($subShape === null ? '' : '{'.$this->encodeFields($subShape).'}');
        }

        return implode($this->delimiter, $fields);
    }

    protected function leafValues(array $object, array $shape): array
    {
        $values = [];

        foreach ($shape as $name => $subShape) {
            $value = $object[$name];

            if ($subShape === null) {
                $values[] = $value;
            } else {
                array_push($values, ...$this->leafValues($value, $subShape));
            }
        }

        return $values;
    }

    protected function joinCells(array $values): string
    {
        return implode($this->delimiter, array_map(fn ($v) => $this->encodePrimitive($v), $values));
    }

    protected function encodePrimitive(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }

        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        if (is_int($v)) {
            return (string) $v;
        }

        if (is_float($v)) {
            return $this->encodeNumber($v);
        }

        if ($v instanceof DateTimeInterface) {
            return $this->encodeString($v->format($this->dateFormat ?? 'Y-m-d\TH:i:sP'), formatDates: false);
        }

        return $this->encodeString((string) $v);
    }

    protected function encodeNumber(float $v): string
    {
        if (is_nan($v) || is_infinite($v)) {
            return 'null';
        }

        if ($this->numberPrecision !== null) {
            $v = round($v, $this->numberPrecision);
        }

        if ($v == 0.0) {
            return '0';
        }

        $abs = abs($v);

        if (floor($v) === $v && $abs < 1e21) {
            return number_format($v, 0, '', '');
        }

        // Shortest round-trip representation, then expanded out of exponent form when in the canonical range.
        $repr = var_export($v, true);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?E([+-]\d+)$/i', $repr, $m)) {
            return $repr;
        }

        [, $sign, $int, $frac, $exp] = $m;
        $digits = ltrim($int.$frac, '0');
        $pointPos = strlen($int) + (int) $exp - (strlen($int.$frac) - strlen($digits));

        if ($abs >= 1e-6 && $abs < 1e21) {
            if ($pointPos <= 0) {
                return $sign.'0.'.str_repeat('0', -$pointPos).rtrim($digits, '0');
            }

            if ($pointPos >= strlen($digits)) {
                return $sign.$digits.str_repeat('0', $pointPos - strlen($digits));
            }

            return $sign.substr($digits, 0, $pointPos).'.'.rtrim(substr($digits, $pointPos), '0');
        }

        $mantissa = rtrim($digits, '0');
        $exponent = $pointPos - 1;
        $mantissa = strlen($mantissa) > 1 ? $mantissa[0].'.'.substr($mantissa, 1) : $mantissa;

        return $sign.$mantissa.'e'.($exponent < 0 ? '-' : '+').abs($exponent);
    }

    protected function encodeString(string $s, bool $formatDates = true): string
    {
        if ($formatDates && $this->dateFormat !== null && $this->looksLikeIsoDate($s)) {
            $s = Carbon::parse($s)->format($this->dateFormat);
        }

        if ($this->truncateStrings !== null && mb_strlen($s) > $this->truncateStrings) {
            $s = mb_substr($s, 0, $this->truncateStrings).'...';
        }

        return $this->needsQuoting($s) ? $this->quote($s) : $s;
    }

    protected function needsQuoting(string $s): bool
    {
        if ($s === '' || $s === 'true' || $s === 'false' || $s === 'null') {
            return true;
        }

        if ($s !== trim($s, " \t")) {
            return true;
        }

        if ($s[0] === '-' || $s[0] === '#') {
            return true;
        }

        if (preg_match('/^[+-]?\d+(?:\.\d+)?(?:e[+-]?\d+)?$/i', $s)) {
            return true;
        }

        if (preg_match('/[:"\\\\\[\]{}\x00-\x1f]/', $s)) {
            return true;
        }

        return str_contains($s, $this->delimiter);
    }

    protected function quote(string $s): string
    {
        $escaped = preg_replace_callback(
            '/[\\\\"\x00-\x1f]/',
            fn (array $m) => match ($m[0]) {
                '\\' => '\\\\',
                '"' => '\\"',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\u%04x', ord($m[0])),
            },
            $s
        );

        return '"'.$escaped.'"';
    }

    protected function encodeKey(string $key): string
    {
        $key = (string) ($this->keyAliases[$key] ?? $key);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key) ? $key : $this->quote($key);
    }

    protected function shouldOmitValue(mixed $val): bool
    {
        $type = match (true) {
            $val === null => 'null',
            $val === '' => 'empty',
            $val === false => 'false',
            default => null,
        };

        return $type !== null && ($this->omitAll || isset($this->omitSet[$type]));
    }

    protected function delimiterSymbol(): string
    {
        return $this->delimiter === ',' ? '' : $this->delimiter;
    }

    protected function indentStr(int $depth): string
    {
        return $this->indentCache[$depth] ??= str_repeat(' ', $this->indent * $depth);
    }

    protected function allPrimitive(array $values): bool
    {
        foreach ($values as $value) {
            if (! $this->isPrimitive($value)) {
                return false;
            }
        }

        return true;
    }

    protected function isPrimitive(mixed $v): bool
    {
        return $v === null || is_scalar($v) || $v instanceof DateTimeInterface;
    }

    protected function looksLikeIsoDate(string $s): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}([T\s]\d{2}:\d{2}(:\d{2})?)?/', $s);
    }

    protected function looksLikeJson(string $s): bool
    {
        $s = trim($s);

        return $s !== '' && (str_starts_with($s, '{') || str_starts_with($s, '['));
    }
}
