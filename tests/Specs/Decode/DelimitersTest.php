<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles delimiter decoding - tab and pipe delimiter parsing, delimiter-aware value splitting',
    function (
        mixed $input,
        mixed $expected,
        array $options = [],
        bool $shouldError = false,
    ) {
        expect(Toon::decode($input))
            ->when(
                $shouldError,
                fn ($e) => $e->toThrow(\Exception::class)
            )
            ->toEqual($expected);
    }
)
    ->with(toonSpecDataset('decode/delimiters'))
    ->group('spec', 'decode');
