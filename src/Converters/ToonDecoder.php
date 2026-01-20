<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Converters;

use Illuminate\Support\Facades\Config;
use MischaSigtermans\Toon\Exceptions\ToonException;
use MischaSigtermans\Toon\Support\ArrayUnflattener;

class ToonDecoder
{
    protected ArrayUnflattener $unflattener;

    protected int $indent;

    protected bool $strict;

    protected string $delimiter;

    public function __construct(?ArrayUnflattener $unflattener = null, ?array $config = null)
    {
        $config ??= Config::get('toon', []);

        $this->unflattener = $unflattener ?? new ArrayUnflattener;
        $this->indent = (int) ($config['indent'] ?? 2);
        $this->strict = (bool) ($config['strict'] ?? true);
        $this->delimiter = (string) ($config['delimiter'] ?? ',');
    }

    public function decode(string $toon): array
    {
        $lines = explode("\n", $toon);
        $lineCount = count($lines);
        $result = [];
        $stack = [&$result];
        $indentStack = [-1];

        $i = 0;
        while ($i < $lineCount) {
            $line = $lines[$i];

            if (trim($line) === '') {
                $i++;

                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));
            $content = trim($line);

            while (count($indentStack) > 1 && $indent <= end($indentStack)) {
                array_pop($stack);
                array_pop($indentStack);
            }

            $arrayMatch = $this->parseArrayHeader($content);
            if ($arrayMatch !== null) {
                $keyName = $arrayMatch['key'];
                $rowCount = $arrayMatch['count'];
                $columns = $arrayMatch['columns'];
                $activeDelimiter = $arrayMatch['delimiter'];
                $inlineValues = $arrayMatch['inline'];

                $isRootLevel = count($stack) === 1 && empty($result);
                $isGenericKey = $keyName === 'items' || $keyName === null;
                $useKeyName = $keyName !== null && (! $isRootLevel || ! $isGenericKey);

                if ($inlineValues !== '' && empty($columns)) {
                    $items = $this->parseRow($inlineValues, $rowCount, $activeDelimiter);

                    $current = &$stack[count($stack) - 1];

                    if ($useKeyName) {
                        $current[$keyName] = $items;
                    } elseif (array_is_list($current)) {
                        $current = array_merge($current, $items);
                    } else {
                        foreach ($items as $item) {
                            $current[] = $item;
                        }
                    }
                } else {
                    $rows = [];

                    for ($j = 0; $j < $rowCount && ($i + 1 + $j) < $lineCount; $j++) {
                        $rowLine = $lines[$i + 1 + $j];
                        $rowContent = trim($rowLine);

                        if ($rowContent === '') {
                            if ($this->strict) {
                                throw ToonException::blankLineInArrayBlock();
                            }

                            continue;
                        }

                        $cells = $this->parseRow($rowContent, count($columns), $activeDelimiter);

                        if ($this->strict && count($columns) > 0 && count($cells) !== count($columns)) {
                            throw ToonException::rowWidthMismatch(count($columns), count($cells));
                        }

                        $rows[] = $cells;
                    }

                    $i += $rowCount;

                    if ($this->strict && count($rows) !== $rowCount) {
                        throw ToonException::arrayLengthMismatch($rowCount, count($rows));
                    }

                    $items = empty($columns)
                        ? $rows
                        : ($this->hasNestedColumns($columns)
                            ? $this->unflattener->unflatten($rows, $columns)
                            : $this->rowsToObjects($rows, $columns));

                    $current = &$stack[count($stack) - 1];

                    if ($useKeyName) {
                        $current[$keyName] = $items;
                    } elseif (array_is_list($current)) {
                        $current = array_merge($current, $items);
                    } else {
                        foreach ($items as $item) {
                            $current[] = $item;
                        }
                    }
                }
            } elseif (str_ends_with($content, ':') && ! $this->containsKeyValueSeparator($content)) {
                $key = $this->parseKey(rtrim($content, ':'));
                $current = &$stack[count($stack) - 1];
                $current[$key] = [];
                $stack[] = &$current[$key];
                $indentStack[] = $indent;
            } elseif ($this->containsKeyValueSeparator($content)) {
                [$key, $value] = $this->splitKeyValue($content);
                $current = &$stack[count($stack) - 1];
                $current[$this->parseKey($key)] = $this->parseValue($value);
            } else {
                $current = &$stack[count($stack) - 1];
                $current[] = $this->parseValue($content);
            }

            $i++;
        }

        return $result;
    }

    protected function parseArrayHeader(string $content): ?array
    {
        if (! preg_match('/^([a-zA-Z_][a-zA-Z0-9_.]*|\".+\")?\[(\d+)(\\\\t|\|)?\](?:\{([^\}]*)\})?:\s*(.*)$/', $content, $m)) {
            return null;
        }

        $keyName = isset($m[1]) && $m[1] !== '' ? $this->parseKey($m[1]) : null;
        $count = (int) $m[2];
        $delimiterMarker = $m[3] ?? '';
        $fieldsStr = $m[4] ?? '';
        $inlineValues = $m[5] ?? '';

        $delimiter = match ($delimiterMarker) {
            '\t' => "\t",
            '|' => '|',
            default => ',',
        };

        $columns = [];
        if ($fieldsStr !== '') {
            $columns = $this->parseRow($fieldsStr, -1, $delimiter);
            $columns = array_map(fn ($c) => is_string($c) ? $c : (string) $c, $columns);
        }

        return [
            'key' => $keyName,
            'count' => $count,
            'columns' => $columns,
            'delimiter' => $delimiter,
            'inline' => trim($inlineValues),
        ];
    }

    protected function containsKeyValueSeparator(string $content): bool
    {
        if (! str_contains($content, ': ')) {
            return false;
        }

        $inQuotes = false;
        $escape = false;
        $len = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $char === ':' && isset($content[$i + 1]) && $content[$i + 1] === ' ') {
                return true;
            }
        }

        return false;
    }

    protected function splitKeyValue(string $content): array
    {
        $inQuotes = false;
        $escape = false;
        $len = strlen($content);

        for ($i = 0; $i < $len; $i++) {
            $char = $content[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $char === ':' && isset($content[$i + 1]) && $content[$i + 1] === ' ') {
                return [
                    substr($content, 0, $i),
                    substr($content, $i + 2),
                ];
            }
        }

        return [$content, ''];
    }

    protected function parseRow(string $row, int $expectedCount, string $delimiter = ','): array
    {
        $cells = [];
        $current = '';
        $inQuotes = false;
        $escape = false;
        $len = strlen($row);

        for ($i = 0; $i < $len; $i++) {
            $char = $row[$i];

            if ($escape) {
                $current .= $char;
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"' && ! $escape) {
                $inQuotes = ! $inQuotes;

                continue;
            }

            if (! $inQuotes && $char === $delimiter) {
                $cells[] = $this->parseValue($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $cells[] = $this->parseValue($current);

        if ($expectedCount > 0) {
            while (count($cells) < $expectedCount) {
                $cells[] = null;
            }
        }

        return $cells;
    }

    protected function parseKey(string $key): string
    {
        $key = trim($key);

        if (str_starts_with($key, '"') && str_ends_with($key, '"')) {
            return $this->unescapeString(substr($key, 1, -1));
        }

        return $key;
    }

    protected function parseValue(string $value): mixed
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return $this->unescapeString(substr($value, 1, -1));
        }

        if ($value === 'null') {
            return null;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if ($this->isNumeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $this->unescapeLegacy($value);
    }

    protected function isNumeric(string $value): bool
    {
        if (preg_match('/^0\d+$/', $value)) {
            return false;
        }

        return (bool) preg_match('/^-?\d+(?:\.\d+)?(?:e[+-]?\d+)?$/i', $value);
    }

    protected function unescapeString(string $s): string
    {
        if (! str_contains($s, '\\')) {
            return $s;
        }

        $result = '';
        $escape = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $char = $s[$i];

            if ($escape) {
                $result .= match ($char) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    default => $this->strict
                        ? throw ToonException::invalidEscapeSequence($char)
                        : $char,
                };
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    protected function unescapeLegacy(string $value): string
    {
        $value = str_replace('\\n', "\n", $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\:', ':', $value);
        $value = str_replace('\\\\', '\\', $value);

        return $value;
    }

    protected function hasNestedColumns(array $columns): bool
    {
        foreach ($columns as $col) {
            if (str_contains($col, '.')) {
                return true;
            }
        }

        return false;
    }

    protected function rowsToObjects(array $rows, array $columns): array
    {
        return array_map(
            fn (array $row) => array_combine($columns, $row) ?: [],
            $rows
        );
    }
}
