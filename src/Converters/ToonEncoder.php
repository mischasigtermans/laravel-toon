<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Converters;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use MischaSigtermans\Toon\Support\ArrayFlattener;

class ToonEncoder
{
    private const EMPTY_OBJECT_MARKER = '__toon_empty_object__';

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

    protected string $keyFolding;

    protected int $flattenDepth;

    protected array $topLevelKeys = [];

    protected array $indentCache = [];

    public function __construct(?ArrayFlattener $flattener = null, ?array $config = null)
    {
        $config ??= Config::get('toon', []);

        if (isset($config['escape_style'])) {
            trigger_error('The "escape_style" config option is deprecated and has been removed in v1.0. Strings are now quoted per TOON v3.0 spec.', E_USER_DEPRECATED);
        }

        $maxDepth = (int) ($config['max_flatten_depth'] ?? PHP_INT_MAX);
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

        $this->keyFolding = (string) ($config['key_folding'] ?? 'off');
        $this->flattenDepth = isset($config['key_folding_depth'])
            ? (int) $config['key_folding_depth']
            : PHP_INT_MAX;
    }

    protected function shouldOmit(string $type): bool
    {
        return $this->omitAll || isset($this->omitSet[$type]);
    }

    protected function shouldSkipKeyValue(string $key, mixed $val): bool
    {
        if (isset($this->omitKeysSet[$key])) {
            return true;
        }

        return match (true) {
            $val === null => $this->shouldOmit('null'),
            $val === '' => $this->shouldOmit('empty'),
            $val === false => $this->shouldOmit('false'),
            default => false,
        };
    }

    protected function indentStr(int $depth): string
    {
        return $this->indentCache[$depth] ??= str_repeat(' ', $this->indent * $depth);
    }

    public function encode(mixed $input): string
    {
        if (is_string($input) && $this->looksLikeJson($input)) {
            $decoded = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $input = $decoded;
            }
        }

        if (is_object($input) || is_array($input)) {
            $input = $this->convertObjectsToArrays($input);
        }

        if (is_array($input)) {
            if ($this->isEmptyObjectMarker($input)) {
                return '';
            }

            if (! array_is_list($input)) {
                $this->topLevelKeys = array_map('strval', array_keys($input));
            } else {
                $this->topLevelKeys = [];
            }

            return $this->valueToToon($input);
        }

        return $this->encodePrimitive($input);
    }

    protected function valueToToon(mixed $value, int $depth = 0, ?string $parentKey = null): string
    {
        $indentStr = $this->indentStr($depth);

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        } elseif ($value instanceof \Traversable && ! $value instanceof \DateTimeInterface) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            if ($this->isEmptyObjectMarker($value)) {
                return '';
            }

            if (empty($value)) {
                if ($depth === 0 && $parentKey === null) {
                    return '[0]:';
                }

                return '';
            }

            if (array_is_list($value)) {
                if ($this->isArrayOfPrimitives($value)) {
                    return $this->inlinePrimitiveArrayToToon($value, $depth, $parentKey);
                }

                if ($this->isArrayOfUniformPrimitiveObjects($value)) {
                    return $this->arrayOfObjectsToToon($value, $depth, $parentKey);
                }

                if ($this->isArrayOfUniformObjects($value) && $this->flattener->hasNestedObjects($value)) {
                    $flattened = $this->flattener->flatten($value);

                    return $this->flattenedToToon($flattened, $depth, $parentKey);
                }

                if ($this->needsListFormat($value)) {
                    return $this->listFormatArrayToToon($value, $depth, $parentKey);
                }

                return $this->sequentialArrayToToon($value, $depth);
            }

            return $this->associativeArrayToToon($value, $depth);
        }

        return $indentStr.$this->encodePrimitive($value);
    }

    protected function flattenedToToon(array $flattened, int $depth, ?string $parentKey = null): string
    {
        $indentStr = $this->indentStr($depth);
        $rowIndent = $this->indentStr($depth + 1);
        $columns = array_map(fn ($col) => $this->encodeKey($col), $flattened['columns']);
        $rows = $flattened['rows'];

        $keyPart = $parentKey !== null ? $this->encodeKey($parentKey) : ($depth === 0 ? '' : 'items');
        $header = $indentStr.$keyPart.'['.$this->getArrayLengthMarker(count($rows)).']{'.implode($this->delimiter, $columns).'}:';

        $rowLines = array_map(
            fn (array $row) => $rowIndent.implode($this->delimiter, array_map(fn ($v) => $this->encodeTableValue($v), $row)),
            $rows
        );

        return $header."\n".implode("\n", $rowLines);
    }

    protected function arrayOfObjectsToToon(array $arr, int $depth, ?string $parentKey = null): string
    {
        $indentStr = $this->indentStr($depth);
        $rowIndent = $this->indentStr($depth + 1);

        $keyPart = $parentKey !== null ? $this->encodeKey($parentKey) : ($depth === 0 ? '' : 'items');

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
        $indentStr = $this->indentStr($depth);
        $keyPart = $parentKey !== null ? $this->encodeKey($parentKey) : ($depth === 0 ? '' : 'items');

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
        $indentStr = $this->indentStr($depth);
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

    protected function listFormatArrayToToon(array $arr, int $depth, ?string $parentKey = null): string
    {
        $indentStr = $this->indentStr($depth);
        $itemIndent = $this->indentStr($depth + 1);
        $keyPart = $parentKey !== null ? $this->encodeKey($parentKey) : ($depth === 0 ? '' : 'items');

        $header = $indentStr.$keyPart.'['.$this->getArrayLengthMarker(count($arr)).']:';
        $lines = [$header];

        foreach ($arr as $item) {
            if ($this->isEmptyObjectMarker($item)) {
                $lines[] = $itemIndent.'-';
            } elseif ($this->isScalar($item)) {
                $lines[] = $itemIndent.'- '.$this->encodePrimitive($item);
            } elseif (is_array($item) && empty($item)) {
                $lines[] = $itemIndent.'- [0]:';
            } elseif (is_array($item) && ! array_is_list($item)) {
                $lines[] = $this->listItemObjectToToon($item, $depth + 1);
            } elseif (is_array($item) && array_is_list($item) && $this->isArrayOfPrimitives($item)) {
                $values = array_map(fn ($v) => $this->encodePrimitive($v), $item);
                $lines[] = $itemIndent.'- ['.$this->getArrayLengthMarker(count($item)).']: '.implode($this->delimiter, $values);
            } elseif (is_array($item) && array_is_list($item)) {
                $lines[] = $this->listItemArray($item, $depth + 1);
            } else {
                $lines[] = $itemIndent.'- '.$this->encodePrimitive($item);
            }
        }

        return implode("\n", $lines);
    }

    protected function listItemObjectToToon(array $obj, int $depth): string
    {
        $indent = $this->indentStr($depth);

        if (empty($obj)) {
            return $indent.'- [0]:';
        }

        $lines = [];
        $isFirst = true;

        foreach ($obj as $key => $val) {
            if ($this->shouldSkipKeyValue((string) $key, $val)) {
                continue;
            }

            $formattedKey = $this->encodeKey((string) $key);
            $prefix = $isFirst ? '- ' : '  ';

            if ($this->isScalar($val)) {
                $lines[] = $indent.$prefix.$formattedKey.': '.$this->encodePrimitive($val);
            } elseif (is_array($val) && empty($val)) {
                $lines[] = $indent.$prefix.$formattedKey.'[0]:';
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfPrimitives($val)) {
                $values = array_map(fn ($v) => $this->encodePrimitive($v), $val);
                $lines[] = $indent.$prefix.$formattedKey.'['.$this->getArrayLengthMarker(count($val)).']: '.implode($this->delimiter, $values);
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfUniformPrimitiveObjects($val)) {
                $lines[] = $this->listItemTabularArray($val, $depth, $formattedKey, $isFirst);
            } elseif (is_array($val) && array_is_list($val)) {
                $lines[] = $this->listItemNestedArray($val, $depth, $formattedKey, $isFirst);
            } else {
                $lines[] = $indent.$prefix.$formattedKey.':';
                $lines[] = $this->valueToToon($val, $depth + 2);
            }

            $isFirst = false;
        }

        return implode("\n", $lines);
    }

    protected function listItemTabularArray(array $arr, int $depth, string $formattedKey, bool $isFirst): string
    {
        $fieldIndent = $this->indentStr($depth);
        $rowIndent = $this->indentStr($depth + 2);
        $prefix = $isFirst ? '- ' : '  ';

        $fields = array_keys((array) $arr[0]);
        $formattedFields = array_map(fn ($f) => $this->encodeKey((string) $f), $fields);
        $header = $fieldIndent.$prefix.$formattedKey.'['.$this->getArrayLengthMarker(count($arr)).']{'.implode($this->delimiter, $formattedFields).'}:';

        $rows = [];
        foreach ($arr as $item) {
            $cells = array_map(fn ($f) => $this->encodeTableValue($item[$f] ?? null), $fields);
            $rows[] = $rowIndent.implode($this->delimiter, $cells);
        }

        return $header."\n".implode("\n", $rows);
    }

    protected function listItemArray(array $arr, int $depth): string
    {
        $itemIndent = $this->indentStr($depth);

        if (empty($arr)) {
            return $itemIndent.'- [0]:';
        }

        if ($this->isArrayOfPrimitives($arr)) {
            $values = array_map(fn ($v) => $this->encodePrimitive($v), $arr);

            return $itemIndent.'- ['.$this->getArrayLengthMarker(count($arr)).']: '.implode($this->delimiter, $values);
        }

        $header = $itemIndent.'- ['.$this->getArrayLengthMarker(count($arr)).']:';
        $lines = [$header];
        $contentIndent = $this->indentStr($depth + 1);

        foreach ($arr as $item) {
            if ($this->isScalar($item)) {
                $lines[] = $contentIndent.'- '.$this->encodePrimitive($item);
            } elseif (is_array($item) && empty($item)) {
                $lines[] = $contentIndent.'- [0]:';
            } elseif (is_array($item) && array_is_list($item) && $this->isArrayOfPrimitives($item)) {
                $values = array_map(fn ($v) => $this->encodePrimitive($v), $item);
                $lines[] = $contentIndent.'- ['.$this->getArrayLengthMarker(count($item)).']: '.implode($this->delimiter, $values);
            } elseif (is_array($item) && ! array_is_list($item)) {
                $lines[] = $this->listItemObjectToToon($item, $depth + 1);
            } else {
                $lines[] = $this->listItemArray($item, $depth + 1);
            }
        }

        return implode("\n", $lines);
    }

    protected function listItemNestedArray(array $arr, int $depth, string $formattedKey, bool $isFirst): string
    {
        $fieldIndent = $this->indentStr($depth);
        $contentIndent = $this->indentStr($depth + 2);
        $prefix = $isFirst ? '- ' : '  ';

        $header = $fieldIndent.$prefix.$formattedKey.'['.$this->getArrayLengthMarker(count($arr)).']:';
        $lines = [$header];

        foreach ($arr as $item) {
            if ($this->isScalar($item)) {
                $lines[] = $contentIndent.'- '.$this->encodePrimitive($item);
            } elseif (is_array($item) && array_is_list($item) && $this->isArrayOfPrimitives($item)) {
                $values = array_map(fn ($v) => $this->encodePrimitive($v), $item);
                $lines[] = $contentIndent.'- ['.$this->getArrayLengthMarker(count($item)).']: '.implode($this->delimiter, $values);
            } elseif (is_array($item) && ! array_is_list($item)) {
                $lines[] = $this->listItemObjectToToon($item, $depth + 2);
            } else {
                $lines[] = $contentIndent.'- '.$this->valueToToon($item, $depth + 3, null);
            }
        }

        return implode("\n", $lines);
    }

    protected function associativeArrayToToon(array $arr, int $depth): string
    {
        $indentStr = $this->indentStr($depth);
        $lines = [];

        foreach ($arr as $key => $val) {
            if ($val instanceof \Illuminate\Contracts\Support\Arrayable) {
                $val = $val->toArray();
            } elseif ($val instanceof \Traversable && ! $val instanceof \DateTimeInterface) {
                $val = iterator_to_array($val);
            }

            if ($this->shouldSkipKeyValue((string) $key, $val)) {
                continue;
            }

            $collisionDetected = false;
            if ($this->keyFolding === 'safe' && ! $this->keyNeedsQuoting((string) $key)) {
                $keyStr = (string) $key;
                $hasCollision = $this->hasKeyCollision($keyStr);

                if (! $hasCollision) {
                    $folded = $this->tryFoldKey($keyStr, $val, 1);
                    if ($folded !== null) {
                        [$foldedKey, $foldedVal] = $folded;
                        $lines[] = $this->encodeFoldedKeyValue($foldedKey, $foldedVal, $depth);

                        continue;
                    }
                } else {
                    $collisionDetected = true;
                }
            }

            $formattedKey = $this->encodeKey((string) $key);

            if ($this->isEmptyObjectMarker($val)) {
                $lines[] = $indentStr.$formattedKey.':';
            } elseif ($this->isScalar($val)) {
                $lines[] = $indentStr.$formattedKey.': '.$this->encodePrimitive($val);
            } elseif (is_array($val) && empty($val)) {
                $lines[] = $indentStr.$formattedKey.'[0]:';
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfPrimitives($val)) {
                $lines[] = $this->inlinePrimitiveArrayToToon($val, $depth, (string) $key);
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfUniformObjects($val) && $this->flattener->hasNestedObjects($val)) {
                $flattened = $this->flattener->flatten($val);
                $lines[] = $this->flattenedToToon($flattened, $depth, (string) $key);
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfUniformPrimitiveObjects($val)) {
                $lines[] = $this->arrayOfObjectsToToon($val, $depth, (string) $key);
            } elseif (is_array($val) && array_is_list($val) && $this->needsListFormat($val)) {
                $lines[] = $this->listFormatArrayToToon($val, $depth, (string) $key);
            } elseif (is_array($val) && ! array_is_list($val) && $collisionDetected) {
                $lines[] = $indentStr.$formattedKey.':';
                $lines[] = $this->associativeArrayToToonNoFolding($val, $depth + 1);
            } else {
                $lines[] = $indentStr.$formattedKey.':';
                $lines[] = $this->valueToToon($val, $depth + 1);
            }
        }

        return implode("\n", $lines);
    }

    protected function hasKeyCollision(string $path): bool
    {
        foreach ($this->topLevelKeys as $existingKey) {
            if ($existingKey !== $path && str_starts_with($existingKey, $path.'.')) {
                return true;
            }
        }

        return false;
    }

    protected function tryFoldKey(string $prefix, mixed $value, int $segmentCount): ?array
    {
        if ($this->hasKeyCollision($prefix)) {
            return null;
        }

        if (! is_array($value) || (! empty($value) && array_is_list($value))) {
            return [$prefix, $value];
        }

        if ($this->isEmptyObjectMarker($value)) {
            return [$prefix, $value];
        }

        if (empty($value)) {
            return [$prefix, $value];
        }

        if (count($value) !== 1) {
            return [$prefix, $value];
        }

        if ($segmentCount >= $this->flattenDepth) {
            return [$prefix, $value];
        }

        $innerKey = (string) array_key_first($value);
        $innerVal = $value[$innerKey];

        if ($this->keyNeedsQuoting($innerKey)) {
            return [$prefix, $value];
        }

        $foldedKey = $prefix.'.'.$innerKey;

        if (in_array($foldedKey, $this->topLevelKeys, true)) {
            return null;
        }

        return $this->tryFoldKey($foldedKey, $innerVal, $segmentCount + 1);
    }

    protected function encodeFoldedKeyValue(string $foldedKey, mixed $value, int $depth): string
    {
        $indentStr = $this->indentStr($depth);

        if ($this->isScalar($value)) {
            return $indentStr.$foldedKey.': '.$this->encodePrimitive($value);
        }

        if ($this->isEmptyObjectMarker($value)) {
            return $indentStr.$foldedKey.':';
        }

        if (is_array($value) && empty($value)) {
            return $indentStr.$foldedKey.':';
        }

        if (is_array($value) && array_is_list($value)) {
            if ($this->isArrayOfPrimitives($value)) {
                $values = array_map(fn ($v) => $this->encodePrimitive($v), $value);

                return $indentStr.$foldedKey.'['.$this->getArrayLengthMarker(count($value)).']: '.implode($this->delimiter, $values);
            }

            if ($this->isArrayOfUniformPrimitiveObjects($value)) {
                return $this->arrayOfObjectsToToon($value, $depth, $foldedKey);
            }

            if ($this->needsListFormat($value)) {
                return $this->listFormatArrayToToon($value, $depth, $foldedKey);
            }
        }

        if (is_array($value) && ! array_is_list($value)) {
            $result = $indentStr.$foldedKey.':';
            $result .= "\n".$this->associativeArrayToToonNoFolding($value, $depth + 1);

            return $result;
        }

        return $indentStr.$foldedKey.': '.$this->encodePrimitive($value);
    }

    protected function associativeArrayToToonNoFolding(array $arr, int $depth): string
    {
        $indentStr = $this->indentStr($depth);
        $lines = [];

        foreach ($arr as $key => $val) {
            if ($val instanceof \Illuminate\Contracts\Support\Arrayable) {
                $val = $val->toArray();
            } elseif ($val instanceof \Traversable && ! $val instanceof \DateTimeInterface) {
                $val = iterator_to_array($val);
            }

            if ($this->shouldSkipKeyValue((string) $key, $val)) {
                continue;
            }

            $formattedKey = $this->encodeKey((string) $key);

            if ($this->isScalar($val)) {
                $lines[] = $indentStr.$formattedKey.': '.$this->encodePrimitive($val);
            } elseif (is_array($val) && empty($val)) {
                $lines[] = $indentStr.$formattedKey.'[0]:';
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfPrimitives($val)) {
                $lines[] = $this->inlinePrimitiveArrayToToon($val, $depth, (string) $key);
            } elseif (is_array($val) && array_is_list($val) && $this->isArrayOfUniformPrimitiveObjects($val)) {
                $lines[] = $this->arrayOfObjectsToToon($val, $depth, (string) $key);
            } elseif (is_array($val) && array_is_list($val) && $this->needsListFormat($val)) {
                $lines[] = $this->listFormatArrayToToon($val, $depth, (string) $key);
            } else {
                $lines[] = $indentStr.$formattedKey.':';
                $lines[] = $this->associativeArrayToToonNoFolding($val, $depth + 1);
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

        if (preg_match('/[:"\\\\{}\x00-\x1f]/', $s)) {
            return true;
        }

        if (str_starts_with($s, '[')) {
            return true;
        }

        if (str_contains($s, $this->delimiter)) {
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
            "\t" => "\t",
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
        $firstTypes = null;

        foreach ($arr as $item) {
            if (! is_array($item) || array_is_list($item)) {
                return false;
            }

            $keys = array_keys($item);
            sort($keys);

            $types = [];
            foreach ($keys as $k) {
                $types[$k] = is_array($item[$k]) ? 'array' : 'scalar';
            }

            if ($firstKeys === null) {
                $firstKeys = $keys;
                $firstTypes = $types;
            } elseif ($keys !== $firstKeys) {
                return false;
            } elseif ($types !== $firstTypes) {
                return false;
            }
        }

        return true;
    }

    protected function isArrayOfUniformPrimitiveObjects(array $arr): bool
    {
        if (! $this->isArrayOfUniformObjects($arr)) {
            return false;
        }

        foreach ($arr as $item) {
            foreach ($item as $val) {
                if (! $this->isScalar($val)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function needsListFormat(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        foreach ($arr as $item) {
            if (is_array($item)) {
                return true;
            }
        }

        return false;
    }

    protected function convertObjectsToArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $arr = (array) $value;
            if (empty($arr)) {
                return [self::EMPTY_OBJECT_MARKER => true];
            }

            return $this->convertObjectsToArrays($arr);
        }

        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            return $this->convertObjectsToArrays($value->toArray());
        }

        if ($value instanceof \Traversable) {
            return $this->convertObjectsToArrays(iterator_to_array($value));
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->convertObjectsToArrays($v), $value);
        }

        return $value;
    }

    protected function isEmptyObjectMarker(mixed $value): bool
    {
        return is_array($value)
            && count($value) === 1
            && isset($value[self::EMPTY_OBJECT_MARKER])
            && $value[self::EMPTY_OBJECT_MARKER] === true;
    }
}
