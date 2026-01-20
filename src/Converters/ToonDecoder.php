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

    protected string $expandPaths;

    protected array $quotedKeys = [];

    protected array $lines;

    protected int $lineCount;

    protected int $pos;

    public function __construct(?ArrayUnflattener $unflattener = null, ?array $config = null)
    {
        $config ??= Config::get('toon', []);

        $this->unflattener = $unflattener ?? new ArrayUnflattener;
        $this->indent = (int) ($config['indent'] ?? 2);
        $this->strict = (bool) ($config['strict'] ?? true);
        $this->delimiter = (string) ($config['delimiter'] ?? ',');
        $this->expandPaths = (string) ($config['expand_paths'] ?? 'off');
    }

    protected function getLineIndent(string $line): int
    {
        $trimmed = ltrim($line);

        if ($trimmed === '') {
            return 0;
        }

        $leadingWhitespace = substr($line, 0, strlen($line) - strlen($trimmed));
        $indentSize = strlen($leadingWhitespace);

        if ($this->strict && $indentSize > 0) {
            if (str_contains($leadingWhitespace, "\t")) {
                throw ToonException::tabInIndentation();
            }

            if ($indentSize % $this->indent !== 0) {
                throw ToonException::invalidIndentation($indentSize, $this->indent);
            }
        }

        return $indentSize;
    }

    public function decode(string $toon): mixed
    {
        $this->lines = explode("\n", $toon);
        $this->lineCount = count($this->lines);
        $this->pos = 0;
        $this->quotedKeys = [];

        $hasContent = false;
        foreach ($this->lines as $line) {
            if (trim($line) !== '') {
                $hasContent = true;
                break;
            }
        }

        if (! $hasContent) {
            return [];
        }

        $result = $this->parseBlock(-1);

        if ($this->expandPaths === 'safe' && ! array_is_list($result)) {
            $result = $this->expandDottedPaths($result);
        }

        if (array_is_list($result) && count($result) === 1) {
            $first = $result[0];
            if (! is_array($first)) {
                return $first;
            }
        }

        return $result;
    }

    protected function expandDottedPaths(array $obj): array
    {
        $result = [];

        foreach ($obj as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $value = $this->expandDottedPaths($value);
            }

            if ($this->shouldExpandKey((string) $key)) {
                $this->setNestedValue($result, (string) $key, $value);
            } else {
                $this->mergeValue($result, (string) $key, $value);
            }
        }

        return $result;
    }

    protected function shouldExpandKey(string $key): bool
    {
        if (in_array($key, $this->quotedKeys, true)) {
            return false;
        }

        if (! str_contains($key, '.')) {
            return false;
        }

        $segments = explode('.', $key);
        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment)) {
                return false;
            }
        }

        return true;
    }

    protected function setNestedValue(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $current = &$target;

        for ($i = 0; $i < count($segments) - 1; $i++) {
            $segment = $segments[$i];

            if (! isset($current[$segment])) {
                $current[$segment] = [];
            } elseif (! is_array($current[$segment])) {
                if ($this->strict) {
                    throw ToonException::pathConflict($path);
                }
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $lastSegment = $segments[count($segments) - 1];
        $this->mergeValue($current, $lastSegment, $value);
    }

    protected function mergeValue(array &$target, string $key, mixed $value): void
    {
        if (! isset($target[$key])) {
            $target[$key] = $value;

            return;
        }

        $existing = $target[$key];

        if (is_array($existing) && ! array_is_list($existing) && is_array($value) && ! array_is_list($value)) {
            foreach ($value as $k => $v) {
                $this->mergeValue($target[$key], (string) $k, $v);
            }

            return;
        }

        if ($this->strict) {
            throw ToonException::pathConflict($key);
        }

        $target[$key] = $value;
    }

    protected function parseBlock(int $minIndent): array
    {
        $result = [];

        while ($this->pos < $this->lineCount) {
            $line = $this->lines[$this->pos];

            if (trim($line) === '') {
                $this->pos++;

                continue;
            }

            $indent = $this->getLineIndent($line);
            $content = trim($line);

            if ($indent <= $minIndent && $minIndent >= 0) {
                break;
            }

            if (str_starts_with($content, '- ') || $content === '-') {
                $itemContent = $content === '-' ? '' : substr($content, 2);
                $this->pos++;
                $item = $this->parseListItem($itemContent, $indent);
                $result[] = $item;

                continue;
            }

            $arrayMatch = $this->parseArrayHeader($content);
            if ($arrayMatch !== null) {
                $keyName = $arrayMatch['key'];
                $rowCount = $arrayMatch['count'];
                $columns = $arrayMatch['columns'];
                $activeDelimiter = $arrayMatch['delimiter'];
                $inlineValues = $arrayMatch['inline'];

                $this->pos++;

                if ($rowCount === 0) {
                    $items = [];
                } elseif ($inlineValues !== '' && $columns === []) {
                    $items = $this->parseRow($inlineValues, $rowCount, $activeDelimiter);
                } elseif ($columns !== []) {
                    $items = $this->parseTabularRows($rowCount, $columns, $activeDelimiter, $indent);
                } else {
                    $items = $this->parseListOrRows($rowCount, $indent, $activeDelimiter);
                }

                if ($keyName !== null) {
                    $result[$keyName] = $items;
                } else {
                    foreach ($items as $item) {
                        $result[] = $item;
                    }
                }

                continue;
            }

            if (str_ends_with($content, ':') && ! $this->containsKeyValueSeparator($content)) {
                $key = $this->parseKey(rtrim($content, ':'));
                $this->pos++;
                $result[$key] = $this->parseBlock($indent);

                continue;
            }

            if ($this->containsKeyValueSeparator($content)) {
                [$key, $value] = $this->splitKeyValue($content);
                $result[$this->parseKey($key)] = $this->parseValue($value);
                $this->pos++;

                continue;
            }

            if ($this->strict && $minIndent >= 0) {
                throw ToonException::missingSyntax('colon in key-value pair');
            }

            if ($this->strict && $minIndent < 0 && ! empty($result) && array_is_list($result)) {
                $hasPrimitive = false;
                foreach ($result as $item) {
                    if (! is_array($item)) {
                        $hasPrimitive = true;
                        break;
                    }
                }
                if ($hasPrimitive) {
                    throw ToonException::invalidRootStructure();
                }
            }

            $result[] = $this->parseValue($content);
            $this->pos++;
        }

        return $result;
    }

    protected function parseListItem(string $content, int $listIndent): mixed
    {
        if ($content === '') {
            return [];
        }

        $arrayMatch = $this->parseArrayHeader($content);
        if ($arrayMatch !== null) {
            $rowCount = $arrayMatch['count'];
            $columns = $arrayMatch['columns'];
            $activeDelimiter = $arrayMatch['delimiter'];
            $inlineValues = $arrayMatch['inline'];
            $keyName = $arrayMatch['key'];

            if ($rowCount === 0) {
                $items = [];
            } elseif ($inlineValues !== '' && $columns === []) {
                $items = $this->parseRow($inlineValues, $rowCount, $activeDelimiter);
            } elseif ($columns !== []) {
                $items = $this->parseTabularRows($rowCount, $columns, $activeDelimiter, $listIndent + 2);
            } else {
                $items = $this->parseListOrRows($rowCount, $listIndent + 2, $activeDelimiter);
            }

            if ($keyName !== null) {
                $obj = [$keyName => $items];
                $this->parseObjectFields($obj, $listIndent);

                return $obj;
            }

            return $items;
        }

        if ($this->containsKeyValueSeparator($content)) {
            [$key, $value] = $this->splitKeyValue($content);
            $obj = [$this->parseKey($key) => $this->parseValue($value)];
            $this->parseObjectFields($obj, $listIndent);

            return $obj;
        }

        if (str_ends_with($content, ':')) {
            $key = $this->parseKey(rtrim($content, ':'));
            $obj = [$key => $this->parseBlock($listIndent + 2)];
            $this->parseObjectFields($obj, $listIndent);

            return $obj;
        }

        return $this->parseValue($content);
    }

    protected function parseObjectFields(array &$obj, int $listIndent): void
    {
        while ($this->pos < $this->lineCount) {
            $line = $this->lines[$this->pos];

            if (trim($line) === '') {
                $this->pos++;

                continue;
            }

            $indent = $this->getLineIndent($line);
            $content = trim($line);

            if ($indent <= $listIndent || str_starts_with($content, '- ') || $content === '-') {
                break;
            }

            $arrayMatch = $this->parseArrayHeader($content);
            if ($arrayMatch !== null) {
                $keyName = $arrayMatch['key'];
                $rowCount = $arrayMatch['count'];
                $columns = $arrayMatch['columns'];
                $activeDelimiter = $arrayMatch['delimiter'];
                $inlineValues = $arrayMatch['inline'];

                $this->pos++;

                if ($rowCount === 0) {
                    $items = [];
                } elseif ($inlineValues !== '' && $columns === []) {
                    $items = $this->parseRow($inlineValues, $rowCount, $activeDelimiter);
                } elseif ($columns !== []) {
                    $items = $this->parseTabularRows($rowCount, $columns, $activeDelimiter, $indent);
                } else {
                    $items = $this->parseListOrRows($rowCount, $indent, $activeDelimiter);
                }

                if ($keyName !== null) {
                    $obj[$keyName] = $items;
                }

                continue;
            }

            if (str_ends_with($content, ':') && ! $this->containsKeyValueSeparator($content)) {
                $key = $this->parseKey(rtrim($content, ':'));
                $this->pos++;
                $obj[$key] = $this->parseBlock($indent);

                continue;
            }

            if ($this->containsKeyValueSeparator($content)) {
                [$key, $value] = $this->splitKeyValue($content);
                $obj[$this->parseKey($key)] = $this->parseValue($value);
                $this->pos++;

                continue;
            }

            break;
        }
    }

    protected function parseTabularRows(int $rowCount, array $columns, string $delimiter, int $baseIndent): array
    {
        $rows = [];

        for ($j = 0; $j < $rowCount && $this->pos < $this->lineCount; $j++) {
            $rowLine = $this->lines[$this->pos];
            $rowContent = trim($rowLine);

            if ($rowContent === '') {
                if ($this->strict) {
                    throw ToonException::blankLineInArrayBlock();
                }
                $this->pos++;
                $j--;

                continue;
            }

            if ($this->strict && count($columns) > 1) {
                $this->validateRowDelimiter($rowContent, $delimiter);
            }

            $cells = $this->parseRow($rowContent, count($columns), $delimiter);

            if ($this->strict && count($cells) !== count($columns)) {
                throw ToonException::rowWidthMismatch(count($columns), count($cells));
            }

            $rows[] = $cells;
            $this->pos++;
        }

        if ($this->strict && count($rows) !== $rowCount) {
            throw ToonException::arrayLengthMismatch($rowCount, count($rows));
        }

        if ($this->strict && $this->pos < $this->lineCount) {
            $nextLine = $this->lines[$this->pos];
            $nextContent = trim($nextLine);
            if ($nextContent !== '') {
                $nextIndent = $this->getLineIndent($nextLine);
                if ($nextIndent > $baseIndent) {
                    throw ToonException::arrayLengthMismatch($rowCount, $rowCount + 1);
                }
            }
        }

        return $this->hasNestedColumns($columns)
            ? $this->unflattener->unflatten($rows, $columns)
            : $this->rowsToObjects($rows, $columns);
    }

    protected function parseListOrRows(int $rowCount, int $baseIndent, string $delimiter): array
    {
        // Peek to see if this is list format
        if ($this->pos < $this->lineCount) {
            $peekLine = $this->lines[$this->pos];
            $peekContent = trim($peekLine);

            if (str_starts_with($peekContent, '- ') || $peekContent === '-') {
                // List format
                return $this->parseListItems($rowCount, $baseIndent);
            }
        }

        // Simple rows (arrays of single values)
        $rows = [];
        for ($j = 0; $j < $rowCount && $this->pos < $this->lineCount; $j++) {
            $rowLine = $this->lines[$this->pos];
            $rowContent = trim($rowLine);

            if ($rowContent === '') {
                if ($this->strict) {
                    throw ToonException::blankLineInArrayBlock();
                }
                $this->pos++;
                $j--;

                continue;
            }

            $rows[] = $this->parseRow($rowContent, -1, $delimiter);
            $this->pos++;
        }

        // If each row has exactly one cell, flatten
        $allSingle = true;
        foreach ($rows as $row) {
            if (count($row) !== 1) {
                $allSingle = false;
                break;
            }
        }

        if ($allSingle) {
            return array_map(fn ($r) => $r[0], $rows);
        }

        return $rows;
    }

    protected function parseListItems(int $expectedCount, int $baseIndent): array
    {
        $items = [];

        while ($this->pos < $this->lineCount && count($items) < $expectedCount) {
            $line = $this->lines[$this->pos];

            if (trim($line) === '') {
                if ($this->strict) {
                    throw ToonException::blankLineInArrayBlock();
                }
                $this->pos++;

                continue;
            }

            $indent = $this->getLineIndent($line);
            $content = trim($line);

            // List items should be at baseIndent or deeper
            if ($indent < $baseIndent && $baseIndent >= 0) {
                break;
            }

            if (str_starts_with($content, '- ') || $content === '-') {
                $itemContent = $content === '-' ? '' : substr($content, 2);
                $this->pos++;
                $items[] = $this->parseListItem($itemContent, $indent);
            } else {
                break;
            }
        }

        if ($this->strict && count($items) !== $expectedCount) {
            throw ToonException::arrayLengthMismatch($expectedCount, count($items));
        }

        // In strict mode, check for extra list items beyond declared count
        if ($this->strict && $this->pos < $this->lineCount) {
            $nextLine = $this->lines[$this->pos];
            $nextContent = trim($nextLine);
            if ($nextContent !== '' && (str_starts_with($nextContent, '- ') || $nextContent === '-')) {
                $nextIndent = $this->getLineIndent($nextLine);
                if ($nextIndent >= $baseIndent || $baseIndent < 0) {
                    throw ToonException::arrayLengthMismatch($expectedCount, $expectedCount + 1);
                }
            }
        }

        return $items;
    }

    protected function parseArrayHeader(string $content): ?array
    {
        if (! preg_match('/^([a-zA-Z_][a-zA-Z0-9_.]*|\".+\")?\[(\d+)([\t|])?\](?:\{([^\}]*)\})?:\s*(.*)$/', $content, $m)) {
            return null;
        }

        $keyName = $m[1] !== '' ? $this->parseKey($m[1]) : null;
        $count = (int) $m[2];
        $delimiterMarker = $m[3];
        $fieldsStr = $m[4];
        $inlineValues = $m[5];

        $delimiter = match ($delimiterMarker) {
            "\t" => "\t",
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

    protected function findUnquoted(string $content, string $needle): int|false
    {
        $inQuotes = false;
        $escape = false;
        $len = strlen($content);
        $needleLen = strlen($needle);

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

            if (! $inQuotes && substr($content, $i, $needleLen) === $needle) {
                return $i;
            }
        }

        return false;
    }

    protected function containsKeyValueSeparator(string $content): bool
    {
        if (! str_contains($content, ': ')) {
            return false;
        }

        return $this->findUnquoted($content, ': ') !== false;
    }

    protected function splitKeyValue(string $content): array
    {
        $pos = $this->findUnquoted($content, ': ');

        if ($pos === false) {
            return [$content, ''];
        }

        return [substr($content, 0, $pos), substr($content, $pos + 2)];
    }

    protected function validateRowDelimiter(string $row, string $expectedDelimiter): void
    {
        $otherDelimiters = [',', "\t", '|'];
        $hasExpected = $this->containsUnquotedDelimiter($row, $expectedDelimiter);

        foreach ($otherDelimiters as $other) {
            if ($other === $expectedDelimiter) {
                continue;
            }

            if ($this->containsUnquotedDelimiter($row, $other)) {
                if (! $hasExpected) {
                    $expectedName = $expectedDelimiter === "\t" ? 'tab' : $expectedDelimiter;
                    $actualName = $other === "\t" ? 'tab' : $other;
                    throw ToonException::delimiterMismatch($expectedName, $actualName);
                }
            }
        }
    }

    protected function containsUnquotedDelimiter(string $content, string $delimiter): bool
    {
        return $this->findUnquoted($content, $delimiter) !== false;
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
                // Preserve the backslash for parseValue to handle escape sequences
                $current .= '\\'.$char;
                $escape = false;

                continue;
            }

            if ($char === '\\') {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;
                $current .= $char;

                continue;
            }

            if (! $inQuotes && $char === $delimiter) {
                $cells[] = $this->parseValue($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if ($inQuotes) {
            throw ToonException::unterminatedString();
        }

        $cells[] = $this->parseValue($current);

        if ($expectedCount > 0) {
            if ($this->strict && count($cells) !== $expectedCount) {
                throw ToonException::arrayLengthMismatch($expectedCount, count($cells));
            }

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
            $unquoted = $this->unescapeString(substr($key, 1, -1));
            $this->quotedKeys[] = $unquoted;

            return $unquoted;
        }

        return $key;
    }

    protected function parseValue(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '"')) {
            if (! str_ends_with($trimmed, '"') || strlen($trimmed) < 2) {
                throw ToonException::unterminatedString();
            }

            return $this->unescapeString(substr($trimmed, 1, -1));
        }

        $value = $trimmed;

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
            // Numbers with decimal points or exponents should be floats
            if (str_contains($value, '.') || stripos($value, 'e') !== false) {
                return (float) $value;
            }

            return (int) $value;
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
