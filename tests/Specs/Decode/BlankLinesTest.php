<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles blank line handling - strict mode errors on blank lines inside arrays, accepts blank lines outside arrays',
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
    ->with(toonSpecDataset('decode/blank-lines'))
    ->group('spec', 'decode');
