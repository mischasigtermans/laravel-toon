<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

beforeEach(function () {
    Toon::clearResolvedInstances();
});

it(
    'handles path expansion with safe mode, deep merge, conflict resolution tied to strict mode',
    function (
        mixed $input,
        mixed $expected,
        array $options = [],
        bool $shouldError = false,
    ) {
        if (! empty($options)) {
            config(['toon' => array_merge(config('toon', []), $options)]);
            Toon::clearResolvedInstances();
        }

        if ($shouldError) {
            expect(fn () => Toon::decode($input))->toThrow(\Exception::class);
        } else {
            expect(Toon::decode($input))->toEqual($expected);
        }
    }
)
    ->with(toonSpecDataset('decode/path-expansion'))
    ->group('spec', 'decode');
