<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it('diff returns correct structure', function () {
    $data = ['name' => 'Alice', 'email' => 'alice@example.com'];

    $diff = Toon::diff($data);

    expect($diff)->toHaveKeys(['json_chars', 'toon_chars', 'saved_chars', 'savings_percent']);
    expect($diff['json_chars'])->toBeInt();
    expect($diff['toon_chars'])->toBeInt();
    expect($diff['saved_chars'])->toBeInt();
    expect($diff['savings_percent'])->toBeFloat();
});

it('diff calculates savings correctly', function () {
    $data = [
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
    ];

    $diff = Toon::diff($data);

    // TOON should be more compact than JSON for tabular data
    expect($diff['saved_chars'])->toBeGreaterThan(0);
    expect($diff['savings_percent'])->toBeGreaterThan(0);
});

it('diff handles empty data', function () {
    $diff = Toon::diff([]);

    expect($diff['json_chars'])->toBe(2); // '[]'
    expect($diff['toon_chars'])->toBe(0); // empty string
});

it('only filters to specified keys', function () {
    $data = [
        'id' => 1,
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'secret',
    ];

    $toon = Toon::only($data, ['id', 'name']);

    expect($toon)->toContain('id: 1');
    expect($toon)->toContain('name: Alice');
    expect($toon)->not->toContain('email');
    expect($toon)->not->toContain('password');
});

it('only works with nested data', function () {
    $data = [
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
        ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
    ];

    $toon = Toon::only($data, ['id', 'name']);

    expect($toon)->toContain('items[2]{id,name}:');
    expect($toon)->toContain('1,Alice');
    expect($toon)->toContain('2,Bob');
    expect($toon)->not->toContain('email');
});

it('only handles missing keys gracefully', function () {
    $data = [
        'id' => 1,
        'name' => 'Alice',
    ];

    $toon = Toon::only($data, ['id', 'email']); // email doesn't exist

    expect($toon)->toContain('id: 1');
    expect($toon)->not->toContain('name');
    expect($toon)->not->toContain('email');
});

it('only preserves key order from filter array', function () {
    $data = [
        'z' => 'last',
        'a' => 'first',
        'm' => 'middle',
    ];

    $toon = Toon::only($data, ['a', 'm', 'z']);

    // Keys should appear in the order specified in the filter
    $lines = explode("\n", $toon);
    expect($lines[0])->toContain('a: first');
    expect($lines[1])->toContain('m: middle');
    expect($lines[2])->toContain('z: last');
});
