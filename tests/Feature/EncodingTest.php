<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it('encodes simple key-value pairs', function () {
    $data = ['name' => 'John', 'age' => 30];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: John');
    expect($toon)->toContain('age: 30');
});

it('encodes nested objects', function () {
    $data = [
        'user' => [
            'name' => 'John',
            'email' => 'john@example.com',
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('user:');
    expect($toon)->toContain('name: John');
    expect($toon)->toContain('email: john@example.com');
});

it('encodes uniform arrays as tables', function () {
    $data = [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('items[2]{id,name}:');
    expect($toon)->toContain('1,Alice');
    expect($toon)->toContain('2,Bob');
});

it('encodes booleans correctly', function () {
    $data = ['active' => true, 'deleted' => false];

    $toon = Toon::encode($data);

    expect($toon)->toContain('active: true');
    expect($toon)->toContain('deleted: false');
});

it('encodes null as empty string', function () {
    $data = [
        ['id' => 1, 'name' => null],
        ['id' => 2, 'name' => 'Bob'],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('1,');
    expect($toon)->toContain('2,Bob');
});

it('escapes special characters', function () {
    $data = ['message' => 'Hello, World: Test'];

    $toon = Toon::encode($data);

    expect($toon)->toContain('Hello\\, World\\: Test');
});

it('handles deeply nested structures', function () {
    $data = [
        'level1' => [
            'level2' => [
                'level3' => 'deep value',
            ],
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('level1:');
    expect($toon)->toContain('level2:');
    expect($toon)->toContain('level3: deep value');
});

it('reduces token count compared to json', function () {
    $data = [
        ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com', 'active' => true],
        ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com', 'active' => false],
        ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com', 'active' => true],
    ];

    $json = json_encode($data);
    $toon = Toon::encode($data);

    expect(strlen($toon))->toBeLessThan(strlen($json));
});

it('omits null values when omit contains null', function () {
    config(['toon.omit' => ['null']]);

    $data = [
        'name' => 'Alice',
        'email' => null,
        'phone' => null,
        'city' => 'Amsterdam',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('city: Amsterdam');
    expect($toon)->not->toContain('email:');
    expect($toon)->not->toContain('phone:');
});

it('includes null values when omit is empty', function () {
    config(['toon.omit' => []]);

    $data = [
        'name' => 'Alice',
        'email' => null,
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('email:');
});

it('omits empty strings when omit contains empty', function () {
    config(['toon.omit' => ['empty']]);

    $data = [
        'name' => 'Alice',
        'bio' => '',
        'city' => 'Amsterdam',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('city: Amsterdam');
    expect($toon)->not->toContain('bio:');
});

it('omits false values when omit contains false', function () {
    config(['toon.omit' => ['false']]);

    $data = [
        'name' => 'Alice',
        'active' => true,
        'deleted' => false,
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('active: true');
    expect($toon)->not->toContain('deleted:');
});

it('omits all value types when omit contains all', function () {
    config(['toon.omit' => ['all']]);

    $data = [
        'name' => 'Alice',
        'email' => null,
        'bio' => '',
        'deleted' => false,
        'city' => 'Amsterdam',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('city: Amsterdam');
    expect($toon)->not->toContain('email:');
    expect($toon)->not->toContain('bio:');
    expect($toon)->not->toContain('deleted:');
});

it('omits specified keys via omit_keys', function () {
    config(['toon.omit_keys' => ['created_at', 'updated_at']]);

    $data = [
        'name' => 'Alice',
        'created_at' => '2024-01-01',
        'updated_at' => '2024-01-02',
        'city' => 'Amsterdam',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('city: Amsterdam');
    expect($toon)->not->toContain('created_at:');
    expect($toon)->not->toContain('updated_at:');
});

it('omit works with nested objects', function () {
    config(['toon.omit' => ['null', 'empty']]);

    $data = [
        'user' => [
            'name' => 'Alice',
            'email' => null,
            'bio' => '',
            'city' => 'Amsterdam',
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('city: Amsterdam');
    expect($toon)->not->toContain('email:');
    expect($toon)->not->toContain('bio:');
});

it('still includes omitted values in tables for column alignment', function () {
    config(['toon.omit' => ['null', 'empty', 'false']]);

    $data = [
        ['id' => 1, 'name' => 'Alice', 'active' => true],
        ['id' => 2, 'name' => null, 'active' => false],
        ['id' => 3, 'name' => '', 'active' => true],
    ];

    $toon = Toon::encode($data);

    // Tables should still have empty cells for omitted values
    expect($toon)->toContain('items[3]{id,name,active}:');
    expect($toon)->toContain('1,Alice,true');
    expect($toon)->toContain('2,,false');
    expect($toon)->toContain('3,,true');
});

it('replaces keys with aliases', function () {
    config(['toon.key_aliases' => ['created_at' => 'c@', 'updated_at' => 'u@']]);

    $data = [
        'name' => 'Alice',
        'created_at' => '2024-01-01',
        'updated_at' => '2024-01-02',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('c@: 2024-01-01');
    expect($toon)->toContain('u@: 2024-01-02');
    expect($toon)->not->toContain('created_at:');
    expect($toon)->not->toContain('updated_at:');
});

it('leaves non-aliased keys unchanged', function () {
    config(['toon.key_aliases' => ['created_at' => 'c@']]);

    $data = [
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'created_at' => '2024-01-01',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('email: alice@example.com');
    expect($toon)->toContain('c@: 2024-01-01');
});

it('applies key aliases in table headers', function () {
    config(['toon.key_aliases' => ['created_at' => 'c@', 'updated_at' => 'u@']]);

    $data = [
        ['id' => 1, 'name' => 'Alice', 'created_at' => '2024-01-01'],
        ['id' => 2, 'name' => 'Bob', 'created_at' => '2024-01-02'],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('items[2]{id,name,c@}:');
    expect($toon)->not->toContain('created_at');
});

it('applies key aliases in nested objects', function () {
    config(['toon.key_aliases' => ['description' => 'desc']]);

    $data = [
        'user' => [
            'name' => 'Alice',
            'description' => 'A software developer',
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('user:');
    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('desc: A software developer');
    expect($toon)->not->toContain('description:');
});

it('formats DateTime objects with date_format', function () {
    config(['toon.date_format' => 'Y-m-d']);

    $data = [
        'name' => 'Alice',
        'created_at' => new \DateTime('2024-01-15 14:30:00'),
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('created_at: 2024-01-15');
});

it('formats ISO date strings with date_format', function () {
    config(['toon.date_format' => 'Y-m-d']);

    $data = [
        'name' => 'Alice',
        'created_at' => '2024-01-15T14:30:00+00:00',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('created_at: 2024-01-15');
});

it('formats date strings without time', function () {
    config(['toon.date_format' => 'd/m/Y']);

    $data = [
        'date' => '2024-01-15',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('date: 15/01/2024');
});

it('limits number precision for floats', function () {
    config(['toon.number_precision' => 2]);

    $data = [
        'pi' => 3.14159265359,
        'price' => 99.999,
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('pi: 3.14');
    expect($toon)->toContain('price: 100.00');
});

it('preserves integers when number_precision is set', function () {
    config(['toon.number_precision' => 2]);

    $data = [
        'count' => 42,
        'price' => 99.5,
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('count: 42');
    expect($toon)->toContain('price: 99.50');
});

it('truncates long strings', function () {
    config(['toon.truncate_strings' => 20]);

    $data = [
        'name' => 'Alice',
        'bio' => 'This is a very long biography that should be truncated.',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('bio: This is a very long ...');
    expect($toon)->not->toContain('truncated');
});

it('does not truncate short strings', function () {
    config(['toon.truncate_strings' => 50]);

    $data = [
        'name' => 'Alice',
        'city' => 'Amsterdam',
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('name: Alice');
    expect($toon)->toContain('city: Amsterdam');
    expect($toon)->not->toContain('...');
});

it('leaves dates unchanged when date_format is null', function () {
    config(['toon.date_format' => null]);

    $data = [
        'created_at' => '2024-01-15T14:30:00+00:00',
    ];

    $toon = Toon::encode($data);

    // Should keep the original ISO format (with escaping for special chars)
    expect($toon)->toContain('2024-01-15T14\\:30\\:00+00\\:00');
});

it('handles Laravel Collections as values', function () {
    $data = [
        'count' => 2,
        'items' => collect([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]),
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('count: 2');
    expect($toon)->toContain('items:');
    expect($toon)->toContain('items[2]{id,name}:');
    expect($toon)->toContain('1,Alice');
    expect($toon)->toContain('2,Bob');
    // Should NOT contain JSON
    expect($toon)->not->toContain('[{"id"');
});

it('handles nested Collections with objects', function () {
    $data = [
        'total' => 2,
        'users' => collect([
            ['id' => 1, 'name' => 'Alice', 'meta' => ['role' => 'admin']],
            ['id' => 2, 'name' => 'Bob', 'meta' => ['role' => 'user']],
        ]),
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('total: 2');
    expect($toon)->toContain('users:');
    // Should flatten nested objects using dot notation
    expect($toon)->toContain('id,name,meta.role');
    expect($toon)->not->toContain('[{"id"');
});

it('encodes a nested Collection via toToon macro', function () {
    $data = collect([
        ['id' => 1, 'name' => 'Alice', 'meta' => collect(['role' => 'admin'])],
        ['id' => 2, 'name' => 'Bob', 'meta' => collect(['role' => 'user'])],
    ]);

    $toon = $data->toToon();

    expect($toon)->toContain('id,name,meta.role');
    expect($toon)->toContain('1,Alice,admin');
    expect($toon)->toContain('2,Bob,user');
    // Should NOT contain JSON
    expect($toon)->not->toContain('[{"id"');
});
