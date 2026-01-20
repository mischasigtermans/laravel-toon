<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles whitespace tolerance in decoding - surrounding spaces around delimiters and values',
    function (
        mixed $input,
        mixed $expected,
        array $options = [],
        bool $shouldError = false,
    ) {
        if (! empty($options)) {
            config(['toon' => array_merge(config('toon', []), $options)]);
        }

        expect(Toon::decode($input))
            ->when(
                $shouldError,
                fn ($e) => $e->toThrow(\Exception::class)
            )
            ->toEqual($expected);
    }
)
    ->with(toonSpecDataset('decode/whitespace'))
    ->group('spec', 'decode');
