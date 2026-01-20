<?php

declare(strict_types=1);

namespace MischaSigtermans\Toon\Exceptions;

use RuntimeException;

class ToonException extends RuntimeException
{
    public static function arrayLengthMismatch(int $declared, int $actual): self
    {
        return new self("Array length mismatch: declared {$declared} rows, got {$actual}");
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

    public static function invalidRootStructure(): self
    {
        return new self('Multiple primitives at root level in strict mode');
    }

    public static function delimiterMismatch(string $expected, string $actual): self
    {
        return new self("Delimiter mismatch: header declares '{$expected}' but row uses '{$actual}'");
    }

    public static function pathConflict(string $path): self
    {
        return new self("Path conflict at '{$path}'");
    }
}
