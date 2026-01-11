<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles path expansion with safe mode, deep merge, conflict resolution tied to strict mode',
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
    ->with(toonSpecDataset('decode/path-expansion'))
    ->group('spec', 'decode');
