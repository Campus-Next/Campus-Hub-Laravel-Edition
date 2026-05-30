<?php

use App\Models\Event;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\EventSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;

beforeEach(function () {
    $this->seed([
        PermissionSeeder::class,
        UserSeeder::class,
        CategorySeeder::class,
        EventSeeder::class,
    ]);
});

test('seeded event exists', function () {
    $events = Event::all();

    expect($events)->not->toBeEmpty();
});

test('seeded event organizer exists', function () {
    $events = Event::all();

    $events->each(function ($event) {
        $this->assertNotNull($event->organizer_id);
    });
});

test('seeded event organizer has admin role', function () {
    $events = Event::all();

    $events->each(function ($event) {
        $organizer = User::find($event->organizer_id);
        $this->assertTrue($organizer->hasRole('admin'));
    });
});
