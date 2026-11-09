<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Converters;

use Illuminate\Support\Facades\Config;
use MischaSigtermans\Toon\Exceptions\ToonException;

class ToonDecoder
{
    protected int $indent;

    protected bool $strict;

    /** @var list<array{depth: int, content: string, line: int}|null> Comment-stripped lines; null marks a blank line. */
    protected array $lines;

    protected int $pos;

    /** @var list<array{int, int}> Line ranges of header spans, checked for blank lines in strict mode. */
    protected array $spans;

    public function __construct(?array $config = null)
    {
        $config ??= Config::get('toon', []);

        $this->indent = max(1, (int) ($config['indent'] ?? 2));
        $this->strict = (bool) ($config['strict'] ?? true);
    }

    public function decode(string $toon): mixed
    {
        $this->lines = $this->scan($toon);
        $this->pos = 0;
        $this->spans = [];

        $this->skipBlank();

        if ($this->pos >= count($this->lines)) {
            return [];
        }

        $result = $this->decodeRoot();

        if ($this->strict) {
            foreach ($this->spans as [$start, $end]) {
                for ($i = $start; $i <= $end; $i++) {
                    if ($this->lines[$i] === null) {
                        throw ToonException::blankLineInArrayBlock();
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return list<array{depth: int, content: string, line: int}|null>
     */
    protected function scan(string $toon): array
    {
        if (str_starts_with($toon, "\u{FEFF}")) {
            $toon = substr($toon, 3);
        }

        $lines = [];

        foreach (explode("\n", $toon) as $i => $raw) {
            if (str_ends_with($raw, "\r")) {
                $raw = substr($raw, 0, -1);
            }

            $raw = rtrim($raw, ' ');
            $spaces = strspn($raw, ' ');
            $content = substr($raw, $spaces);

            if ($content === '') {
                $lines[] = null;

                continue;
            }

            if ($content[0] === '#') {
                continue;
            }

            $tabs = strspn($content, "\t");

            if ($tabs > 0) {
                if ($this->strict) {
                    throw ToonException::tabInIndentation();
                }

                // Non-strict mode counts each leading tab as one indentation level.
                $content = substr($content, $tabs);
            }

            if ($this->strict && $spaces % $this->indent !== 0) {
                throw ToonException::invalidIndentation($spaces, $this->indent);
            }

            $lines[] = ['depth' => intdiv($spaces, $this->indent) + $tabs, 'content' => $content, 'line' => $i + 1];
        }

        return $lines;
    }

    protected function decodeRoot(): mixed
    {
        $first = $this->lines[$this->pos];

        if ($first['depth'] === 0) {
            $header = $this->parseHeader($first['content']);

            if ($header !== null && $header['key'] === null) {
                $this->pos++;
                $value = $header['keyed']
                    ? $this->keyedBody($header, 1)
                    : $this->arrayBody($header, 1);
                $this->assertNoTrailingContent();

                return $value;
            }

            if ($first['content'] === '[]') {
                $this->pos++;
                $this->assertNoTrailingContent();

                return [];
            }
        }

        if ($this->countContentLines() === 1 && $this->findUnquoted($first['content'], ':') === null) {
            $this->pos++;

            return $this->parsePrimitive($first['content']);
        }

        $object = [];
        $this->objectFields($object, 0);

        return $object;
    }

    protected function assertNoTrailingContent(): void
    {
        $this->skipBlank();

        if ($this->strict && $this->pos < count($this->lines)) {
            throw ToonException::trailingContent($this->lines[$this->pos]['line']);
        }
    }

    /**
     * Read fields at exactly $depth into $object until the scope ends.
     */
    protected function objectFields(array &$object, int $depth): void
    {
        while (($line = $this->nextLineAt($depth)) !== null) {
            $this->pos++;
            [$key, $value] = $this->field($line['content'], $depth + 1);
            $this->assign($object, $key, $value);
        }
    }

    /**
     * Parse one key-value or header line whose nested content opens at $contentDepth.
     *
     * @return array{string, mixed}
     */
    protected function field(string $content, int $contentDepth): array
    {
        $header = $this->parseHeader($content);

        if ($header !== null) {
            if ($header['key'] === null) {
                if ($this->strict) {
                    throw ToonException::misplacedHeader();
                }
            } else {
                $value = $header['keyed']
                    ? $this->keyedBody($header, $contentDepth)
                    : $this->arrayBody($header, $contentDepth);

                return [$header['key'], $value];
            }
        }

        $colon = $this->findUnquoted($content, ':');

        if ($colon === null) {
            throw ToonException::missingSyntax('colon after key');
        }

        $key = $this->parseKey(substr($content, 0, $colon));
        $rest = $this->trimSpaces(substr($content, $colon + 1));

        if ($rest === '') {
            return [$key, $this->nestedObject($contentDepth)];
        }

        return [$key, $rest === '[]' ? [] : $this->parsePrimitive($rest)];
    }

    protected function nestedObject(int $contentDepth): array
    {
        $next = $this->peek();

        if ($next === null || $next['depth'] < $contentDepth) {
            return [];
        }

        if ($next['depth'] > $contentDepth) {
            if ($this->strict) {
                throw ToonException::indentationJump($next['line']);
            }

            $contentDepth = $next['depth'];
        }

        $object = [];
        $this->objectFields($object, $contentDepth);

        return $object;
    }

    /**
     * Body of a non-keyed header whose items, rows or list entries sit at $contentDepth.
     */
    protected function arrayBody(array $header, int $contentDepth): array
    {
        if ($header['fields'] !== null) {
            return $this->tabularRows($header, $contentDepth);
        }

        if ($header['inline'] !== '') {
            $values = array_map(
                fn (string $token) => $this->parsePrimitive($token),
                $this->splitCells($header['inline'], $header['delimiter'])
            );

            $this->assertCount($header['length'], count($values));

            return $values;
        }

        return $this->listItems($header, $contentDepth);
    }

    protected function tabularRows(array $header, int $depth): array
    {
        $rows = [];
        $start = null;

        while (($line = $this->nextLineAt($depth)) !== null) {
            $content = $line['content'];
            $colon = $this->findUnquoted($content, ':');
            $delimiter = $this->findUnquoted($content, $header['delimiter']);

            if ($colon !== null && ($delimiter === null || $colon < $delimiter)) {
                break;
            }

            $start ??= $this->pos;
            $this->pos++;
            $rows[] = $this->materialize($header, $this->rowCells($content, $header));
        }

        $this->recordSpan($start);
        $this->assertCount($header['length'], count($rows));

        return $rows;
    }

    protected function keyedBody(array $header, int $depth): array
    {
        $object = [];
        $count = 0;
        $start = null;

        while (($line = $this->nextLineAt($depth)) !== null) {
            $colon = $this->findUnquoted($line['content'], ':');

            if ($colon === null) {
                if ($this->strict) {
                    throw ToonException::missingSyntax('colon in keyed entry row');
                }

                $this->pos++;

                continue;
            }

            $start ??= $this->pos;
            $this->pos++;
            $count++;

            $key = $this->parseKey(substr($line['content'], 0, $colon));
            $cells = $this->rowCells(substr($line['content'], $colon + 1), $header);
            $this->assign($object, $key, $this->materialize($header, $cells));
        }

        $this->recordSpan($start);
        $this->assertCount($header['length'], $count);

        return $object;
    }

    protected function listItems(array $header, int $depth): array
    {
        $items = [];
        $start = null;

        while (($line = $this->nextLineAt($depth)) !== null) {
            $content = $line['content'];

            if (! $this->isListItem($content)) {
                break;
            }

            $start ??= $this->pos;
            $this->pos++;
            $items[] = $this->listItem($this->trimSpaces(substr($content, 1)), $depth);
        }

        $this->recordSpan($start);
        $this->assertCount($header['length'], count($items));

        return $items;
    }

    protected function listItem(string $content, int $depth): mixed
    {
        if ($content === '' || $content === '[]') {
            return [];
        }

        $header = $this->parseHeader($content);

        if ($header !== null && $header['key'] === null) {
            if ($header['fields'] === null && ! $header['keyed']) {
                return $this->arrayBody($header, $depth + 1);
            }

            if ($this->strict) {
                throw ToonException::misplacedHeader();
            }
        }

        if ($header === null && $this->findUnquoted($content, ':') === null) {
            return $this->parsePrimitive($content);
        }

        // A first field on the hyphen line stands at $depth + 1; its own content opens at $depth + 2.
        [$key, $value] = $this->field($content, $depth + 2);
        $object = [$key => $value];
        $this->objectFields($object, $depth + 1);

        return $object;
    }

    /**
     * @return list<string>
     */
    protected function rowCells(string $content, array $header): array
    {
        $content = $this->trimSpaces($content);

        return $content === '' ? [] : $this->splitCells($content, $header['delimiter']);
    }

    /**
     * Walk the field tree depth-first, consuming one cell per leaf field.
     */
    protected function materialize(array $header, array $cells): array
    {
        $index = 0;

        if ($this->strict && count($cells) !== $header['leaves']) {
            throw ToonException::rowWidthMismatch($header['leaves'], count($cells));
        }

        return $this->materializeGroup($header['fields'], $cells, $index);
    }

    protected function materializeGroup(array $fields, array $cells, int &$index): array
    {
        $object = [];

        foreach ($fields as [$name, $children]) {
            if ($children !== null) {
                $object[$name] = $this->materializeGroup($children, $cells, $index);

                continue;
            }

            if (array_key_exists($index, $cells)) {
                $object[$name] = $this->parsePrimitive($cells[$index]);
            }

            $index++;
        }

        return $object;
    }

    protected function leafCount(array $fields): int
    {
        $count = 0;

        foreach ($fields as [, $children]) {
            $count += $children === null ? 1 : $this->leafCount($children);
        }

        return $count;
    }

    /**
     * Parse an array or keyed header. Returns null when the line is not a header;
     * throws in strict mode when it is a malformed one.
     *
     * @return array{key: ?string, length: int, keyed: bool, delimiter: string, fields: ?array, inline: string, leaves: int}|null
     */
    protected function parseHeader(string $content): ?array
    {
        $key = null;

        if ($content[0] === '"') {
            $end = $this->closingQuote($content, 0);

            if ($end === null || ($content[$end + 1] ?? '') !== '[') {
                return null;
            }

            $key = $this->unescape(substr($content, 1, $end - 1));
            $bracket = $end + 1;
        } else {
            $bracket = $this->findUnquoted($content, '[');
            $colon = $this->findUnquoted($content, ':');

            if ($bracket === null || $colon === null || $colon < $bracket) {
                return null;
            }

            if ($bracket > 0) {
                $key = substr($content, 0, $bracket);

                if ($key !== rtrim($key, " \t")) {
                    return $this->malformedHeader();
                }
            }
        }

        $close = strpos($content, ']', $bracket);

        if ($close === false || ! preg_match('/^(0|[1-9]\d*)(:)?([\t|])?$/', substr($content, $bracket + 1, $close - $bracket - 1), $m)) {
            return $this->malformedHeader();
        }

        $delimiter = ($m[3] ?? '') === '' ? ',' : $m[3];
        $keyed = ($m[2] ?? '') === ':';
        $pos = $close + 1;
        $fields = null;

        if (($content[$pos] ?? '') === '{') {
            $fields = $this->parseFieldGroup($content, $pos, $delimiter);

            if ($fields === null) {
                return $this->malformedHeader();
            }
        }

        if (($content[$pos] ?? '') !== ':') {
            return $this->malformedHeader();
        }

        if ($keyed && $fields === null) {
            return $this->malformedHeader();
        }

        $inline = $this->trimSpaces(substr($content, $pos + 1));

        if ($fields !== null && $inline !== '') {
            return $this->malformedHeader();
        }

        return [
            'key' => $key,
            'length' => (int) $m[1],
            'keyed' => $keyed,
            'delimiter' => $delimiter,
            'fields' => $fields,
            'inline' => $inline,
            'leaves' => $fields === null ? 0 : $this->leafCount($fields),
        ];
    }

    protected function malformedHeader(): null
    {
        if ($this->strict) {
            throw ToonException::malformedHeader();
        }

        return null;
    }

    /**
     * Parse `{a,b{c,d}}` starting at $pos, leaving $pos just past the closing brace.
     *
     * @return list<array{string, ?array}>|null
     */
    protected function parseFieldGroup(string $content, int &$pos, string $delimiter): ?array
    {
        $pos++;
        $fields = [];
        $names = [];
        $len = strlen($content);

        while (true) {
            $start = $pos;

            if (($content[$pos] ?? '') === '"') {
                $end = $this->closingQuote($content, $pos);

                if ($end === null) {
                    throw ToonException::unterminatedString();
                }

                $name = $this->unescape(substr($content, $pos + 1, $end - $pos - 1));
                $pos = $end + 1;
            } else {
                while ($pos < $len && ! in_array($content[$pos], ['{', '}', $delimiter], true)) {
                    $pos++;
                }

                $name = $this->trimSpaces(substr($content, $start, $pos - $start));

                if ($name === '' || strpbrk($name, ",\t|:[]") !== false) {
                    return null;
                }
            }

            $children = null;

            if (($content[$pos] ?? '') === '{') {
                $children = $this->parseFieldGroup($content, $pos, $delimiter);

                if ($children === null) {
                    return null;
                }
            }

            if (isset($names[$name]) && $this->strict) {
                throw ToonException::duplicateKey($name);
            }

            $names[$name] = true;
            $fields[] = [$name, $children];

            $char = $content[$pos] ?? '';

            if ($char === '}') {
                $pos++;

                return $fields;
            }

            if ($char !== $delimiter) {
                return null;
            }

            $pos++;
        }
    }

    protected function assign(array &$object, string $key, mixed $value): void
    {
        if ($this->strict && array_key_exists($key, $object)) {
            throw ToonException::duplicateKey($key);
        }

        $object[$key] = $value;
    }

    protected function assertCount(int $declared, int $actual): void
    {
        if ($this->strict && $declared !== $actual) {
            throw ToonException::arrayLengthMismatch($declared, $actual);
        }
    }

    /**
     * The next line at exactly $depth, handling deeper orphan lines on the way; null once the scope ends.
     *
     * @return array{depth: int, content: string, line: int}|null
     */
    protected function nextLineAt(int $depth): ?array
    {
        while (($line = $this->peek()) !== null && $line['depth'] >= $depth) {
            if ($line['depth'] === $depth) {
                return $line;
            }

            $this->orphan($line);
        }

        return null;
    }

    protected function isListItem(string $content): bool
    {
        return $content === '-' || str_starts_with($content, '- ');
    }

    protected function orphan(array $line): void
    {
        $content = $line['content'];
        $isScalar = ! $this->isListItem($content) && $this->findUnquoted($content, ':') === null;

        if ($this->strict || $isScalar) {
            throw ToonException::indentationJump($line['line']);
        }

        $this->pos++;
    }

    protected function recordSpan(?int $start): void
    {
        if ($start !== null) {
            $this->spans[] = [$start, $this->lastContentLine()];
        }
    }

    protected function lastContentLine(): int
    {
        $i = $this->pos - 1;

        while ($this->lines[$i] === null) {
            $i--;
        }

        return $i;
    }

    /**
     * @return array{depth: int, content: string, line: int}|null
     */
    protected function peek(): ?array
    {
        $this->skipBlank();

        return $this->lines[$this->pos] ?? null;
    }

    protected function skipBlank(): void
    {
        while ($this->pos < count($this->lines) && $this->lines[$this->pos] === null) {
            $this->pos++;
        }
    }

    protected function countContentLines(): int
    {
        return count(array_filter($this->lines));
    }

    /**
     * @return list<string>
     */
    protected function splitCells(string $content, string $delimiter): array
    {
        $cells = [];
        $start = 0;

        while (($end = $this->findUnquoted($content, $delimiter, $start)) !== null) {
            $cells[] = $this->trimSpaces(substr($content, $start, $end - $start));
            $start = $end + 1;
        }

        $cells[] = $this->trimSpaces(substr($content, $start));

        return $cells;
    }

    protected function findUnquoted(string $content, string $needle, int $offset = 0): ?int
    {
        $inQuotes = false;
        $len = strlen($content);

        for ($i = $offset; $i < $len; $i++) {
            $char = $content[$i];

            if ($inQuotes && $char === '\\') {
                $i++;

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;
            } elseif (! $inQuotes && $char === $needle) {
                return $i;
            }
        }

        return null;
    }

    protected function closingQuote(string $content, int $open): ?int
    {
        $len = strlen($content);

        for ($i = $open + 1; $i < $len; $i++) {
            if ($content[$i] === '\\') {
                $i++;
            } elseif ($content[$i] === '"') {
                return $i;
            }
        }

        return null;
    }

    protected function parseKey(string $token): string
    {
        $token = $this->trimSpaces($token);

        if (str_starts_with($token, '"')) {
            return $this->parseQuoted($token);
        }

        return $token;
    }

    protected function parsePrimitive(string $token): mixed
    {
        $token = $this->trimSpaces($token);

        if (str_starts_with($token, '"')) {
            return $this->parseQuoted($token);
        }

        return match (true) {
            $token === 'null' => null,
            $token === 'true' => true,
            $token === 'false' => false,
            $this->isNumber($token) => $this->toNumber($token),
            default => $token,
        };
    }

    protected function parseQuoted(string $token): string
    {
        $end = $this->closingQuote($token, 0);

        if ($end === null) {
            throw ToonException::unterminatedString();
        }

        if ($end !== strlen($token) - 1) {
            throw ToonException::contentAfterQuote();
        }

        return $this->unescape(substr($token, 1, -1));
    }

    protected function isNumber(string $token): bool
    {
        return (bool) preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:e[+-]?\d+)?$/i', $token);
    }

    protected function toNumber(string $token): int|float
    {
        if (preg_match('/^-?\d+$/', $token)) {
            $int = filter_var($token, FILTER_VALIDATE_INT);

            if ($int !== false) {
                return $int;
            }
        }

        $float = (float) $token;

        return $float == 0.0 ? 0 : $float;
    }

    protected function unescape(string $s): string
    {
        if (! str_contains($s, '\\')) {
            return $s;
        }

        return preg_replace_callback('/\\\\(u(.{0,4})|.?)/su', function (array $m) {
            $escape = $m[1];

            if ($escape !== '' && $escape[0] === 'u') {
                $hex = $m[2];

                if (! preg_match('/^[0-9a-f]{4}$/i', $hex)) {
                    throw ToonException::invalidEscapeSequence($escape);
                }

                $code = hexdec($hex);

                if ($code >= 0xD800 && $code <= 0xDFFF) {
                    throw ToonException::invalidEscapeSequence($escape);
                }

                return mb_chr((int) $code, 'UTF-8');
            }

            return match ($escape) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '"' => '"',
                '\\' => '\\',
                default => throw ToonException::invalidEscapeSequence($escape),
            };
        }, $s);
    }

    protected function trimSpaces(string $s): string
    {
        return trim($s, ' ');
    }
}
