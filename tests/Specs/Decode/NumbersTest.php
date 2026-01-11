<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles number decoding edge cases - trailing zeros, exponent forms, negative zero',
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
    ->with(toonSpecDataset('decode/numbers'))
    ->group('spec', 'decode');
