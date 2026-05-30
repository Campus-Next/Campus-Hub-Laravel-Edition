<?php

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;

beforeEach(function () {
    $this->seed([
        PermissionSeeder::class,
        UserSeeder::class,
        CategorySeeder::class,
    ]);

    $this->organizer = User::where('email', 'organizer@example.com')->first();
    $this->user = User::where('email', 'user@example.com')->first();
});

function listingEvent(int $organizerId, array $attributes = []): Event
{
    return Event::factory()->create(array_merge(['organizer_id' => $organizerId], $attributes));
}

function listingParticipant(User $user, Event $event, string $status): void
{
    EventParticipant::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'status' => $status,
        'unique_code' => null,
    ]);
}

// ===== GET /events =====

test('events are paginated when pagination params are present', function () {
    Event::factory()->count(15)->create(['organizer_id' => $this->organizer->id]);

    $this->getJson('/api/events?per_page=5&page=1')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.total', 15)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonCount(5, 'data');
});

test('events listing stays unpaginated (legacy) without pagination params', function () {
    Event::factory()->count(3)->create(['organizer_id' => $this->organizer->id]);

    $response = $this->getJson('/api/events')->assertOk()->assertJsonCount(3, 'data');

    expect($response->json())->not->toHaveKey('meta');
});

test('events search narrows results by title', function () {
    listingEvent($this->organizer->id, ['title' => 'Laravel Workshop']);
    listingEvent($this->organizer->id, ['title' => 'Vue Seminar']);

    $this->getJson('/api/events?per_page=10&search=Laravel')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Laravel Workshop');
});

test('events sort by title orders alphabetically', function () {
    listingEvent($this->organizer->id, ['title' => 'Zebra Expo']);
    listingEvent($this->organizer->id, ['title' => 'Alpha Expo']);

    $this->getJson('/api/events?per_page=10&sort=title')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Alpha Expo')
        ->assertJsonPath('data.1.title', 'Zebra Expo');
});

test('events sort by date orders by start_date ascending', function () {
    listingEvent($this->organizer->id, ['title' => 'Later', 'start_date' => now()->addDays(30)]);
    listingEvent($this->organizer->id, ['title' => 'Sooner', 'start_date' => now()->addDays(2)]);

    $this->getJson('/api/events?per_page=10&sort=date')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Sooner')
        ->assertJsonPath('data.1.title', 'Later');
});

test('events per_page is capped at 50', function () {
    Event::factory()->count(3)->create(['organizer_id' => $this->organizer->id]);

    $this->getJson('/api/events?per_page=1000')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 50);
});

test('events reject an invalid sort option', function () {
    $this->getJson('/api/events?sort=bogus&page=1')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sort']);
});

// ===== GET /my-events?scope=organized =====

test('organized events are paginated and searchable', function () {
    Event::factory()->count(8)->create(['organizer_id' => $this->organizer->id]);
    listingEvent($this->organizer->id, ['title' => 'Special Gala']);

    $this->actingAs($this->organizer, 'api')
        ->getJson('/api/my-events?scope=organized&per_page=5')
        ->assertOk()
        ->assertJsonPath('meta.total', 9)
        ->assertJsonCount(5, 'data');

    $this->actingAs($this->organizer, 'api')
        ->getJson('/api/my-events?scope=organized&per_page=5&search=Gala')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.title', 'Special Gala');
});

test('organized scope forbids non-admins', function () {
    $this->actingAs($this->user, 'api')
        ->getJson('/api/my-events?scope=organized&per_page=5')
        ->assertForbidden();
});

// ===== GET /my-events?scope=registered =====

function seedRegistered(User $user, int $organizerId): void
{
    listingParticipant($user, listingEvent($organizerId, ['title' => 'Alpha Conf']), 'registered');
    listingParticipant($user, listingEvent($organizerId, ['title' => 'Beta Conf']), 'registered');
    listingParticipant($user, listingEvent($organizerId, ['title' => 'Gamma Workshop']), 'attended');
    listingParticipant($user, listingEvent($organizerId, ['title' => 'Delta Talk']), 'absent');
    listingParticipant($user, listingEvent($organizerId, ['title' => 'Omega Meetup']), 'cancelled');
}

test('registered events are paginated with status counts', function () {
    seedRegistered($this->user, $this->organizer->id);

    $this->actingAs($this->user, 'api')
        ->getJson('/api/my-events?scope=registered&per_page=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.counts.all', 5)
        ->assertJsonPath('meta.counts.registered', 2)
        ->assertJsonPath('meta.counts.attended', 1)
        ->assertJsonPath('meta.counts.absent', 1)
        ->assertJsonPath('meta.counts.cancelled', 1);
});

test('registered status filter narrows results but counts stay whole', function () {
    seedRegistered($this->user, $this->organizer->id);

    $this->actingAs($this->user, 'api')
        ->getJson('/api/my-events?scope=registered&per_page=10&status=registered')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.counts.all', 5)
        ->assertJsonPath('meta.counts.attended', 1);
});

test('registered search constrains both results and counts', function () {
    seedRegistered($this->user, $this->organizer->id);

    $this->actingAs($this->user, 'api')
        ->getJson('/api/my-events?scope=registered&per_page=10&search=Conf')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.counts.all', 2)
        ->assertJsonPath('meta.counts.registered', 2)
        ->assertJsonPath('meta.counts.attended', 0);
});

// ===== GET /events/{event}/participants =====

test('participants are paginated, filterable, searchable and sortable with counts', function () {
    $event = listingEvent($this->organizer->id);

    $alice = User::factory()->create(['name' => 'Alice Adams']);
    $bob = User::factory()->create(['name' => 'Bob Brown']);
    $carol = User::factory()->create(['name' => 'Carol Clark']);

    listingParticipant($alice, $event, 'registered');
    listingParticipant($bob, $event, 'attended');
    listingParticipant($carol, $event, 'registered');

    // counts
    $this->actingAs($this->organizer, 'api')
        ->getJson("/api/events/{$event->id}/participants?per_page=10")
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.counts.all', 3)
        ->assertJsonPath('meta.counts.registered', 2)
        ->assertJsonPath('meta.counts.attended', 1);

    // status filter (counts stay whole)
    $this->actingAs($this->organizer, 'api')
        ->getJson("/api/events/{$event->id}/participants?per_page=10&status=attended")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.user.name', 'Bob Brown')
        ->assertJsonPath('meta.counts.all', 3);

    // search by participant name
    $this->actingAs($this->organizer, 'api')
        ->getJson("/api/events/{$event->id}/participants?per_page=10&search=Alice")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.user.name', 'Alice Adams');

    // sort by name
    $this->actingAs($this->organizer, 'api')
        ->getJson("/api/events/{$event->id}/participants?per_page=10&sort=name")
        ->assertOk()
        ->assertJsonPath('data.0.user.name', 'Alice Adams')
        ->assertJsonPath('data.2.user.name', 'Carol Clark');
});

test('participants endpoint denies an admin who does not own the event', function () {
    $event = listingEvent($this->organizer->id);
    $otherAdmin = User::where('email', 'admin@example.com')->first();

    $this->actingAs($otherAdmin, 'api')
        ->getJson("/api/events/{$event->id}/participants?per_page=10")
        ->assertForbidden();
});
