<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it(
    'handles blank line handling - strict mode errors on blank lines inside arrays, accepts blank lines outside arrays',
    function (
        mixed $input,
        mixed $expected,
        array $options,
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
    ->with(
        array_map(
            fn (array $s) => [
                'input' => $s['input'],
                'expected' => $s['expected'] ?? null,
                'options' => $s['options'] ?? [],
                'shouldError' => $s['shouldError'] ?? false,
            ],
            array_column(json_decode(file_get_contents(
                __DIR__.'/../../../node_modules/@toon-format/spec/tests/fixtures/decode/blank-lines.json'
            ), true)['tests'], null, 'name'))
    )
    ->group('spec', 'decode');
