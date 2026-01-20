<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it('decodes simple key-value pairs', function () {
    $toon = "name: John\nage: 30";

    $decoded = Toon::decode($toon);

    expect($decoded)->toBe(['name' => 'John', 'age' => 30]);
});

it('decodes nested objects', function () {
    $toon = "user:\n  name: John\n  email: john@example.com";

    $decoded = Toon::decode($toon);

    expect($decoded)->toBe([
        'user' => [
            'name' => 'John',
            'email' => 'john@example.com',
        ],
    ]);
});

it('decodes tabular arrays', function () {
    $toon = "[2]{id,name}:\n  1,Alice\n  2,Bob";

    $decoded = Toon::decode($toon);

    expect($decoded)->toBe([
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ]);
});

it('decodes booleans correctly', function () {
    $toon = "active: true\ndeleted: false";

    $decoded = Toon::decode($toon);

    expect($decoded)->toBe(['active' => true, 'deleted' => false]);
});

it('decodes null values', function () {
    $toon = "[2]{id,name}:\n  1,\n  2,Bob";

    $decoded = Toon::decode($toon);

    expect($decoded[0]['name'])->toBeNull();
    expect($decoded[1]['name'])->toBe('Bob');
});

it('unescapes special characters from quoted strings', function () {
    $toon = 'message: "Hello, World: Test"';

    $decoded = Toon::decode($toon);

    expect($decoded['message'])->toBe('Hello, World: Test');
});

it('supports legacy backslash escaping for backward compatibility', function () {
    config(['toon.strict' => false]);
    $toon = 'message: Hello\, World\: Test';

    $decoded = Toon::decode($toon);

    expect($decoded['message'])->toBe('Hello, World: Test');
});

it('handles numeric values correctly', function () {
    $toon = "count: 42\nprice: 19.99";

    $decoded = Toon::decode($toon);

    expect($decoded['count'])->toBe(42);
    expect($decoded['price'])->toBe(19.99);
});
