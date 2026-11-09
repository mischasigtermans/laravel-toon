<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it('encodes nested objects in arrays as nested field groups', function () {
    $data = [
        'bookings' => [
            ['id' => 'abc', 'status' => 'confirmed', 'artist' => ['id' => 'xyz', 'name' => 'DJ Test']],
            ['id' => 'def', 'status' => 'pending', 'artist' => ['id' => 'uvw', 'name' => 'Band']],
        ],
    ];

    expect(Toon::encode($data))->toBe(implode("\n", [
        'bookings[2]{id,status,artist{id,name}}:',
        '  abc,confirmed,xyz,DJ Test',
        '  def,pending,uvw,Band',
    ]));
});

it('decodes nested field groups', function () {
    $toon = "[2]{id,artist{id,name}}:\n  abc,xyz,DJ Test\n  def,uvw,Band";

    expect(Toon::decode($toon))->toBe([
        ['id' => 'abc', 'artist' => ['id' => 'xyz', 'name' => 'DJ Test']],
        ['id' => 'def', 'artist' => ['id' => 'uvw', 'name' => 'Band']],
    ]);
});

it('round-trips nested objects correctly', function () {
    $data = [
        ['id' => 1, 'user' => ['name' => 'John', 'email' => 'john@test.com']],
        ['id' => 2, 'user' => ['name' => 'Jane', 'email' => 'jane@test.com']],
    ];

    expect(Toon::decode(Toon::encode($data)))->toEqual($data);
});

it('handles multi-level nesting', function () {
    $data = [
        ['id' => 1, 'event' => ['name' => 'Festival', 'venue' => ['name' => 'Club X', 'city' => 'Amsterdam']]],
        ['id' => 2, 'event' => ['name' => 'Concert', 'venue' => ['name' => 'Arena Y', 'city' => 'Rotterdam']]],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('[2]{id,event{name,venue{name,city}}}:');
    expect($toon)->toContain('1,Festival,Club X,Amsterdam');
    expect(Toon::decode($toon))->toEqual($data);
});

it('falls back to list form when nested objects differ in shape', function () {
    $data = [
        ['id' => 1, 'artist' => ['name' => 'DJ A']],
        ['id' => 2, 'artist' => ['name' => 'DJ B', 'genre' => 'Techno']],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toStartWith("[2]:\n  - id: 1");
    expect(Toon::decode($toon))->toEqual($data);
});

it('encodes objects of uniform objects in keyed tabular form', function () {
    $data = [
        'stations' => [
            'tempelhof' => ['lat' => 52.47, 'lon' => 13.4, 'active' => true],
            'tegel' => ['lat' => 52.55, 'lon' => 13.29, 'active' => false],
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toBe(implode("\n", [
        'stations[2:]{lat,lon,active}:',
        '  tempelhof: 52.47,13.4,true',
        '  tegel: 52.55,13.29,false',
    ]));
    expect(Toon::decode($toon))->toEqual($data);
});

it('keeps dotted keys literal inside nested field groups', function () {
    $data = [
        ['dimensions' => ['campaign.name' => 'My campaign A'], 'metrics' => ['sessions' => 100]],
        ['dimensions' => ['campaign.name' => 'My campaign B'], 'metrics' => ['sessions' => 50]],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toBe(implode("\n", [
        '[2]{dimensions{campaign.name},metrics{sessions}}:',
        '  My campaign A,100',
        '  My campaign B,50',
    ]));
    expect(Toon::decode($toon))->toBe($data);
});

it('handles the booking example', function () {
    $data = [
        'count' => 2,
        'bookings' => [
            [
                'id' => 'abc123',
                'status' => 'confirmed',
                'artist' => ['id' => 'art1', 'name' => 'DJ Awesome'],
                'financial' => ['currency' => 'EUR', 'artist_fee' => 2500],
            ],
            [
                'id' => 'def456',
                'status' => 'pending',
                'artist' => ['id' => 'art2', 'name' => 'Band Cool'],
                'financial' => ['currency' => 'EUR', 'artist_fee' => 1500],
            ],
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('bookings[2]{id,status,artist{id,name},financial{currency,artist_fee}}:');
    expect($toon)->toContain('abc123,confirmed,art1,DJ Awesome,EUR,2500');
    expect(Toon::decode($toon))->toBe($data);
});

it('does not use field groups for flat arrays', function () {
    $toon = Toon::encode([
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ]);

    expect($toon)->toContain('[2]{id,name}:');
    expect($toon)->not->toContain('{id,name{');
});
