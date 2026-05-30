<?php

use App\Models\Event;
use App\Models\EventAbsentSchedule;
use App\Models\EventParticipant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->seed([
        PermissionSeeder::class,
        UserSeeder::class,
    ]);
});

function adminUser(): User
{
    return User::where('email', 'admin@example.com')->firstOrFail();
}

function eventPayload(array $overrides = []): array
{
    $startDate = now()->addDays(3)->setSecond(0);
    $endDate = now()->addDays(3)->addHours(2)->setSecond(0);

    return [
        'title' => 'Scheduled Event',
        'description' => 'An event that should create an absent schedule.',
        'start_date' => $startDate->format('Y-m-d H:i:s'),
        'end_date' => $endDate->format('Y-m-d H:i:s'),
        'location' => 'Main Hall',
        'max_participants' => 100,
        ...$overrides,
    ];
}

test('creating an event creates an absent schedule matching end date', function () {
    $payload = eventPayload();

    $response = $this->actingAs(adminUser(), 'api')->postJson('/api/events', $payload);

    $response->assertCreated();

    $this->assertDatabaseHas('event_absent_schedules', [
        'event_id' => $response->json('data.id'),
        'run_at' => $payload['end_date'],
        'processed_at' => null,
        'cancelled_at' => null,
    ]);
});

test('updating an unprocessed event updates its absent schedule', function () {
    $response = $this->actingAs(adminUser(), 'api')->postJson('/api/events', eventPayload());
    $eventId = $response->json('data.id');
    $newStartDate = now()->addDays(6)->setSecond(0);
    $newEndDate = now()->addDays(6)->addHours(3)->setSecond(0);

    $this->actingAs(adminUser(), 'api')->patchJson("/api/events/{$eventId}", [
        'start_date' => $newStartDate->format('Y-m-d H:i:s'),
        'end_date' => $newEndDate->format('Y-m-d H:i:s'),
    ])->assertOk();

    $this->assertDatabaseHas('event_absent_schedules', [
        'event_id' => $eventId,
        'run_at' => $newEndDate->format('Y-m-d H:i:s'),
        'processed_at' => null,
    ]);
});

test('updating an already processed event does not roll back its schedule', function () {
    $response = $this->actingAs(adminUser(), 'api')->postJson('/api/events', eventPayload());
    $eventId = $response->json('data.id');
    $originalRunAt = EventAbsentSchedule::where('event_id', $eventId)->firstOrFail()->run_at;

    EventAbsentSchedule::where('event_id', $eventId)->update(['processed_at' => now()]);

    $newStartDate = now()->addDays(8)->setSecond(0);
    $newEndDate = now()->addDays(8)->addHours(4)->setSecond(0);

    $this->actingAs(adminUser(), 'api')->patchJson("/api/events/{$eventId}", [
        'start_date' => $newStartDate->format('Y-m-d H:i:s'),
        'end_date' => $newEndDate->format('Y-m-d H:i:s'),
    ])->assertOk();

    $schedule = EventAbsentSchedule::where('event_id', $eventId)->firstOrFail();

    expect($schedule->run_at->equalTo($originalRunAt))->toBeTrue();
    expect($schedule->processed_at)->not->toBeNull();
});

test('deleting an event deletes its absent schedule', function () {
    $response = $this->actingAs(adminUser(), 'api')->postJson('/api/events', eventPayload());
    $eventId = $response->json('data.id');

    $this->actingAs(adminUser(), 'api')->deleteJson("/api/events/{$eventId}")
        ->assertOk();

    $this->assertDatabaseMissing('event_absent_schedules', [
        'event_id' => $eventId,
    ]);
});

test('process absent schedules command marks only registered participants absent and is idempotent', function () {
    $admin = adminUser();
    $registeredUser = User::factory()->create();
    $attendedUser = User::factory()->create();
    $event = Event::factory()->create([
        'organizer_id' => $admin->id,
        'start_date' => now()->subHours(3),
        'end_date' => now()->subHour(),
        'registration_open' => now()->subDays(2),
        'registration_deadline' => now()->subHours(4),
    ]);

    EventAbsentSchedule::create([
        'event_id' => $event->id,
        'run_at' => now()->subMinute(),
    ]);

    $registeredParticipant = EventParticipant::create([
        'user_id' => $registeredUser->id,
        'event_id' => $event->id,
        'status' => 'registered',
        'unique_code' => 'A1B2',
    ]);

    $attendedParticipant = EventParticipant::create([
        'user_id' => $attendedUser->id,
        'event_id' => $event->id,
        'status' => 'attended',
        'unique_code' => 'C3D4',
    ]);

    Artisan::call('events:process-absent-schedules');
    Artisan::call('events:process-absent-schedules');

    expect($registeredParticipant->fresh()->status)->toBe('absent');
    expect($attendedParticipant->fresh()->status)->toBe('attended');
    expect(EventAbsentSchedule::where('event_id', $event->id)->firstOrFail()->processed_at)->not->toBeNull();
});
