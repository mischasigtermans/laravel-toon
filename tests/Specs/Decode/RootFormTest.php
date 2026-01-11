<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles root form detection - empty document, single primitive, multiple primitives',
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
    ->with(toonSpecDataset('decode/root-form'))
    ->group('spec', 'decode');
