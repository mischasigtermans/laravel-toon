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

    // Uses dot-notation flattening for nested objects (key folding per TOON spec)
    expect($toon)->toContain('bookings[2]{id,status,artist.id,artist.name}:');
    expect($toon)->toContain('abc,confirmed,xyz,DJ Test');
});

it('decodes nested objects from dot notation columns', function () {
    $toon = "[2]{id,artist.id,artist.name}:\n  abc,xyz,DJ Test\n  def,uvw,Band";

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

    // Uses dot-notation flattening for multi-level nesting
    expect($toon)->toContain('[2]{id,event.name,event.venue.name,event.venue.city}:');
    expect($toon)->toContain('1,Festival,Club X,Amsterdam');

    $decoded = Toon::decode($toon);

    expect($decoded)->toEqual($data);
});

it('handles missing nested properties gracefully', function () {
    $data = [
        ['id' => 1, 'artist' => ['name' => 'DJ A']],
        ['id' => 2, 'artist' => ['name' => 'DJ B', 'genre' => 'Techno']],
    ];

    $toon = Toon::encode($data);

    // Uses dot-notation flattening, missing values become null
    expect($toon)->toContain('[2]{id,artist.name,artist.genre}:');
    expect($toon)->toContain('1,DJ A,null');
    expect($toon)->toContain('2,DJ B,Techno');

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

    // Uses dot-notation flattening for nested objects
    expect($toon)->toContain('count: 2');
    expect($toon)->toContain('bookings[2]{id,status,artist.id,artist.name,event.id,event.name,financial.currency,financial.artist_fee}:');
    expect($toon)->toContain('abc123,confirmed,art1,DJ Awesome,evt1,Summer Festival,EUR,2500');

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

    expect($toon)->toContain('[2]{id,name}:');
    expect($toon)->not->toContain('.');
});
