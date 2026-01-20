<?php

declare(strict_types=1);

use MischaSigtermans\Toon\Facades\Toon;

it('encodes nested objects in arrays using dot notation', function () {
    $data = [
        'bookings' => [
            ['id' => 'abc', 'status' => 'confirmed', 'artist' => ['id' => 'xyz', 'name' => 'DJ Test']],
            ['id' => 'def', 'status' => 'pending', 'artist' => ['id' => 'uvw', 'name' => 'Band']],
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('bookings[2]{id,status,artist.id,artist.name}:');
    expect($toon)->toContain('abc,confirmed,xyz,DJ Test');
    expect($toon)->toContain('def,pending,uvw,Band');
});

it('decodes nested objects from dot notation columns', function () {
    $toon = "items[2]{id,artist.id,artist.name}:\n  abc,xyz,DJ Test\n  def,uvw,Band";

    $decoded = Toon::decode($toon);

    expect($decoded)->toBe([
        ['id' => 'abc', 'artist' => ['id' => 'xyz', 'name' => 'DJ Test']],
        ['id' => 'def', 'artist' => ['id' => 'uvw', 'name' => 'Band']],
    ]);
});

it('round-trips nested objects correctly', function () {
    $data = [
        ['id' => 1, 'user' => ['name' => 'John', 'email' => 'john@test.com']],
        ['id' => 2, 'user' => ['name' => 'Jane', 'email' => 'jane@test.com']],
    ];

    $encoded = Toon::encode($data);
    $decoded = Toon::decode($encoded);

    expect($decoded)->toEqual($data);
});

it('handles multi-level nesting', function () {
    $data = [
        [
            'id' => 1,
            'event' => [
                'name' => 'Festival',
                'venue' => ['name' => 'Club X', 'city' => 'Amsterdam'],
            ],
        ],
        [
            'id' => 2,
            'event' => [
                'name' => 'Concert',
                'venue' => ['name' => 'Arena Y', 'city' => 'Rotterdam'],
            ],
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('event.name');
    expect($toon)->toContain('event.venue.name');
    expect($toon)->toContain('event.venue.city');

    $decoded = Toon::decode($toon);

    expect($decoded)->toEqual($data);
});

it('handles missing nested properties gracefully', function () {
    $data = [
        ['id' => 1, 'artist' => ['name' => 'DJ A']],
        ['id' => 2, 'artist' => ['name' => 'DJ B', 'genre' => 'Techno']],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('artist.name');
    expect($toon)->toContain('artist.genre');

    $decoded = Toon::decode($toon);

    expect($decoded[0]['artist']['genre'] ?? null)->toBeNull();
    expect($decoded[1]['artist']['genre'])->toBe('Techno');
});

it('handles the critical booking example from stagent', function () {
    $data = [
        'count' => 2,
        'total_count' => 2,
        'bookings' => [
            [
                'id' => 'abc123',
                'status' => 'confirmed',
                'artist' => ['id' => 'art1', 'name' => 'DJ Awesome'],
                'event' => ['id' => 'evt1', 'name' => 'Summer Festival'],
                'financial' => ['currency' => 'EUR', 'artist_fee' => 2500],
            ],
            [
                'id' => 'def456',
                'status' => 'pending',
                'artist' => ['id' => 'art2', 'name' => 'Band Cool'],
                'event' => ['id' => 'evt1', 'name' => 'Summer Festival'],
                'financial' => ['currency' => 'EUR', 'artist_fee' => 1500],
            ],
        ],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('count: 2');
    expect($toon)->toContain('artist.id');
    expect($toon)->toContain('artist.name');
    expect($toon)->toContain('event.id');
    expect($toon)->toContain('financial.currency');

    $decoded = Toon::decode($toon);

    expect($decoded)->toHaveKey('count');
    expect($decoded['count'])->toBe(2);
    expect($decoded['bookings'][0]['artist']['name'])->toBe('DJ Awesome');
    expect($decoded['bookings'][0]['financial']['artist_fee'])->toBe(2500);
    expect($decoded['bookings'][1]['financial']['artist_fee'])->toBe(1500);
});

it('does not flatten non-nested arrays', function () {
    $data = [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ];

    $toon = Toon::encode($data);

    expect($toon)->toContain('items[2]{id,name}:');
    expect($toon)->not->toContain('.');
});
