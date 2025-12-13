<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Converters;

use MischaSigtermans\Toon\Support\ArrayUnflattener;

class ToonDecoder
{
    protected ArrayUnflattener $unflattener;

    public function __construct(?ArrayUnflattener $unflattener = null)
    {
        $this->unflattener = $unflattener ?? new ArrayUnflattener;
    }

    public function decode(string $toon): array
    {
        $lines = explode("\n", $toon);
        $result = [];
        $stack = [&$result];
        $indentStack = [-1];

        $i = 0;
        while ($i < count($lines)) {
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

            if (preg_match('/^items\[(\d+)\]\{([^\}]*)\}:$/', $content, $m)) {
                $rowCount = (int) $m[1];
                $columns = $m[2] !== '' ? array_map('trim', explode(',', $m[2])) : [];
                $rows = [];

                for ($j = 0; $j < $rowCount && ($i + 1 + $j) < count($lines); $j++) {
                    $rowLine = $lines[$i + 1 + $j];
                    $rowContent = trim($rowLine);

                    if ($rowContent === '') {
                        continue;
                    }

                    $cells = $this->parseRow($rowContent, count($columns));
                    $rows[] = $cells;
                }

                $i += $rowCount;

                $items = $this->hasNestedColumns($columns)
                    ? $this->unflattener->unflatten($rows, $columns)
                    : $this->rowsToObjects($rows, $columns);

                $current = &$stack[count($stack) - 1];

                /** @phpstan-ignore function.alreadyNarrowedType */
                if (array_is_list($current)) {
                    $current = array_merge($current, $items);
                } else {
                    $current = $items;
                }
            } elseif (str_ends_with($content, ':') && ! str_contains($content, ': ')) {
                $key = rtrim($content, ':');
                $current = &$stack[count($stack) - 1];
                $current[$key] = [];
                $stack[] = &$current[$key];
                $indentStack[] = $indent;
            } elseif (str_contains($content, ': ')) {
                [$key, $value] = explode(': ', $content, 2);
                $current = &$stack[count($stack) - 1];
                $current[$key] = $this->parseValue($value);
            } else {
                $current = &$stack[count($stack) - 1];
                $current[] = $this->parseValue($content);
            }

            $i++;
        }

        return $result;
    }

    /**
     * @return array<mixed>
     */
    protected function parseRow(string $row, int $expectedCount): array
    {
        $cells = [];
        $current = '';
        $escaped = false;

        for ($i = 0; $i < strlen($row); $i++) {
            $char = $row[$i];

            if ($escaped) {
                $current .= $char;
                $escaped = false;

                continue;
            }

            if ($char === '\\') {
                $escaped = true;

                continue;
            }

            if ($char === ',') {
                $cells[] = $this->parseValue($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $cells[] = $this->parseValue($current);

        while (count($cells) < $expectedCount) {
            $cells[] = null;
        }

        return $cells;
    }

    protected function parseValue(string $value): mixed
    {
        $value = trim($value);

        if ($value === '' || $value === 'null') {
            return null;
        }

        if ($value === 'true') {
            return true;
        }

        if ($value === 'false') {
            return false;
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        $value = str_replace('\\n', "\n", $value);
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\:', ':', $value);
        $value = str_replace('\\\\', '\\', $value);

        return $value;
    }

    /**
     * @param  array<string>  $columns
     */
    protected function hasNestedColumns(array $columns): bool
    {
        foreach ($columns as $col) {
            if (str_contains($col, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array<mixed>>  $rows
     * @param  array<string>  $columns
     * @return array<array<string, mixed>>
     */
    protected function rowsToObjects(array $rows, array $columns): array
    {
        return array_map(
            fn (array $row) => array_combine($columns, $row) ?: [],
            $rows
        );
    }
}
