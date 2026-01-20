<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles primitive value encoding - strings, numbers, booleans, null',
    function (
        mixed $input,
        mixed $expected,
        array $options = [],
        bool $shouldError = false,
    ) {
        if (! empty($options)) {
            config(['toon' => array_merge(config('toon', []), $options)]);
        }

        expect(Toon::encode($input))
            ->when(
                $shouldError,
                fn ($e) => $e->toThrow(\Exception::class)
            )
            ->toEqual($expected);
    }
)
    ->with(toonSpecDataset('encode/primitives'))
    ->group('spec', 'encode');
