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

test('public users can list events', function () {
    $event = Event::latest()->first();

    $response = $this->getJson('/api/events');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.title', $event->title)
        ->assertJsonPath('data.0.location', $event->location);
});

test('public users can view an event', function () {
    $event = Event::first();

    $response = $this->getJson("/api/events/{$event->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', $event->title)
        ->assertJsonPath('data.description', $event->description);
});

test('public users cannot create events', function () {
    $response = $this->postJson('/api/events', [
        'title' => 'Unauthorized Event',
    ]);

    $response->assertUnauthorized();
});

test('regular users cannot write events', function () {
    $event = Event::first();
    $user = User::where('email', 'user@example.com')->first();

    $this->actingAs($user, 'api')->postJson('/api/events', [
        'title' => 'Hacked Event',
    ])->assertForbidden();

    $this->actingAs($user, 'api')->patchJson("/api/events/{$event->id}", [
        'title' => 'Hacked Event Title',
    ])->assertForbidden();

    $this->actingAs($user, 'api')->deleteJson("/api/events/{$event->id}")
        ->assertForbidden();
});

test('admins can create events', function () {
    $admin = User::where('email', 'organizer@example.com')->first();

    $response = $this->actingAs($admin, 'api')->postJson('/api/events', [
        'organizer_id' => $admin->id,
        'title' => 'New Event',
        'description' => 'A completely new event',
        'start_date' => now()->addDays(10)->toDateString(),
        'end_date' => now()->addDays(12)->toDateString(),
        'location' => 'Online',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'New Event')
        ->assertJsonPath('data.location', 'Online');

    $this->assertDatabaseHas('events', [
        'title' => 'New Event',
        'location' => 'Online',
    ]);
});

test('admins can update events', function () {
    $admin = User::where('email', 'organizer@example.com')->first();
    $event = Event::factory()->create([
        'organizer_id' => $admin->id,
    ]);

    $response = $this->actingAs($admin, 'api')->patchJson("/api/events/{$event->id}", [
        'title' => 'Updated Workshop',
        'location' => 'Room 202',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.title', 'Updated Workshop')
        ->assertJsonPath('data.location', 'Room 202');

    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'title' => 'Updated Workshop',
        'location' => 'Room 202',
    ]);
});

test('event timeline validation uses existing values on partial updates', function () {
    $admin = User::where('email', 'organizer@example.com')->first();
    $event = Event::factory()->create([
        'organizer_id' => $admin->id,
        'start_date' => now()->addDays(2),
        'end_date' => now()->addDays(2)->addHours(2),
        'registration_open' => now()->subDay(),
        'registration_deadline' => now()->addDay(),
    ]);

    $this->actingAs($admin, 'api')->patchJson("/api/events/{$event->id}", [
        'registration_deadline' => now()->addDays(3)->format('Y-m-d H:i:s'),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['registration_deadline']);

    $this->actingAs($admin, 'api')->patchJson("/api/events/{$event->id}", [
        'start_date' => now()->addHours(12)->format('Y-m-d H:i:s'),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['registration_deadline']);
});

test('admins can delete events', function () {
    $admin = User::where('email', 'organizer@example.com')->first();
    $event = Event::factory()->create([
        'organizer_id' => $admin->id,
    ]);

    $response = $this->actingAs($admin, 'api')->deleteJson("/api/events/{$event->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Event deleted successfully');

    $this->assertDatabaseMissing('events', [
        'id' => $event->id,
    ]);
});
