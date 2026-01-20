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
}
