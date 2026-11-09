<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Exceptions;

use RuntimeException;

class ToonException extends RuntimeException
{
    public static function arrayLengthMismatch(int $declared, int $actual): self
    {
        return new self("Array length mismatch: declared {$declared} items, got {$actual}");
    }

    public static function rowWidthMismatch(int $expected, int $actual): self
    {
        return new self("Row width mismatch: expected {$expected} columns, got {$actual}");
    }

    public static function blankLineInArrayBlock(): self
    {
        return new self('Blank line inside array block');
    }

    public static function invalidEscapeSequence(string $char): self
    {
        return new self("Invalid escape sequence: \\{$char}");
    }

    public static function tabInIndentation(): self
    {
        return new self('Tab character in indentation');
    }

    public static function invalidIndentation(int $indent, int $expected): self
    {
        return new self("Indentation {$indent} is not a multiple of {$expected}");
    }

    public static function unterminatedString(): self
    {
        return new self('Unterminated string');
    }

    public static function missingSyntax(string $expected): self
    {
        return new self("Missing {$expected}");
    }

    public static function malformedHeader(): self
    {
        return new self('Malformed array header');
    }

    public static function misplacedHeader(): self
    {
        return new self('Keyless header outside its valid position');
    }

    public static function duplicateKey(string $key): self
    {
        return new self("Duplicate key '{$key}'");
    }

    public static function indentationJump(int $line): self
    {
        return new self("Line {$line} is indented deeper than its enclosing scope");
    }

    public static function trailingContent(int $line): self
    {
        return new self("Unexpected content on line {$line} after the root value");
    }

    public static function contentAfterQuote(): self
    {
        return new self('Unexpected characters after closing quote');
    }
}
