<?php

use App\Models\Cart;
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

function cancelFlowUser(): User
{
    return User::where('email', 'user@example.com')->firstOrFail();
}

function cancelFlowAdmin(): User
{
    return User::where('email', 'admin@example.com')->firstOrFail();
}

function cancelFlowEvent(array $overrides = []): Event
{
    return Event::factory()->create([
        'organizer_id' => cancelFlowAdmin()->id,
        'start_date' => now()->addDays(3),
        'end_date' => now()->addDays(3)->addHours(2),
        'registration_open' => now()->subDay(),
        'registration_deadline' => now()->addDays(2),
        'max_participants' => 10,
        ...$overrides,
    ]);
}

test('registered participant cancellation is stored as cancelled history', function () {
    $user = cancelFlowUser();
    $event = cancelFlowEvent();

    $this->actingAs($user, 'api')->postJson("/api/events/{$event->id}/enroll")
        ->assertCreated();

    $response = $this->actingAs($user, 'api')->deleteJson("/api/events/{$event->id}/enroll");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.unique_code', null);

    $participant = EventParticipant::where('event_id', $event->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($participant->status)->toBe('cancelled');
    expect($participant->unique_code)->toBeNull();
    expect($participant->cancelled_at)->not->toBeNull();
});

test('cancel endpoint is idempotent for already cancelled participant', function () {
    $user = cancelFlowUser();
    $event = cancelFlowEvent();

    EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'cancelled',
        'unique_code' => null,
        'cancelled_at' => now(),
    ]);

    $this->actingAs($user, 'api')->deleteJson("/api/events/{$event->id}/enroll")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'cancelled');
});

test('attended and absent participants cannot cancel registration', function (string $status) {
    $user = cancelFlowUser();
    $event = cancelFlowEvent();

    EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => $status,
        'unique_code' => strtoupper(substr($status, 0, 4)),
    ]);

    $this->actingAs($user, 'api')->deleteJson("/api/events/{$event->id}/enroll")
        ->assertStatus(422)
        ->assertJsonPath('success', false);
})->with(['attended', 'absent']);

test('cancelled participant can re-enroll with a new code', function () {
    $user = cancelFlowUser();
    $event = cancelFlowEvent();

    $participant = EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'cancelled',
        'unique_code' => null,
        'cancelled_at' => now(),
    ]);

    $this->actingAs($user, 'api')->postJson("/api/events/{$event->id}/enroll")
        ->assertOk()
        ->assertJsonPath('data.id', $participant->id)
        ->assertJsonPath('data.status', 'registered');

    $participant->refresh();

    expect($participant->status)->toBe('registered');
    expect($participant->unique_code)->not->toBeNull();
    expect($participant->cancelled_at)->toBeNull();
});

test('checkout reactivates cancelled participant', function () {
    $user = cancelFlowUser();
    $event = cancelFlowEvent();

    $participant = EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'cancelled',
        'unique_code' => null,
        'cancelled_at' => now(),
    ]);

    Cart::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user, 'api')->postJson('/api/carts/checkout')
        ->assertOk()
        ->assertJsonPath('data.enrolled.0.id', $participant->id)
        ->assertJsonPath('data.enrolled.0.status', 'registered');

    $participant->refresh();

    expect($participant->status)->toBe('registered');
    expect($participant->unique_code)->not->toBeNull();
    expect($participant->cancelled_at)->toBeNull();
    expect(Cart::where('user_id', $user->id)->count())->toBe(0);
});

test('cancelled participants do not consume capacity', function () {
    $cancelledUser = cancelFlowUser();
    $newUser = User::factory()->create();
    $newUser->assignRole('user');
    $event = cancelFlowEvent(['max_participants' => 1]);

    EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => $cancelledUser->id,
        'status' => 'cancelled',
        'unique_code' => null,
        'cancelled_at' => now(),
    ]);

    $this->actingAs($newUser, 'api')->postJson("/api/events/{$event->id}/enroll")
        ->assertCreated()
        ->assertJsonPath('data.status', 'registered');

    $this->getJson("/api/events/{$event->id}")
        ->assertOk()
        ->assertJsonPath('data.participants_count', 1);
});

test('checkout skips full events without enrolling participant', function () {
    $user = cancelFlowUser();
    $existingUser = User::factory()->create();
    $existingUser->assignRole('user');
    $event = cancelFlowEvent(['max_participants' => 1]);

    EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => $existingUser->id,
        'status' => 'registered',
        'unique_code' => 'FULL',
    ]);

    Cart::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user, 'api')->postJson('/api/carts/checkout')
        ->assertOk()
        ->assertJsonCount(0, 'data.enrolled')
        ->assertJsonPath('data.skipped.0.reason', 'Maximum participants reached');

    $this->assertDatabaseMissing('event_participants', [
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);
    expect(Cart::where('user_id', $user->id)->count())->toBe(0);
});

test('my events defaults to registered scope and keeps participant response shape', function () {
    $user = cancelFlowUser();
    $event = cancelFlowEvent();
    $participant = EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'registered',
        'unique_code' => 'MINE',
    ]);

    $this->actingAs($user, 'api')->getJson('/api/my-events')
        ->assertOk()
        ->assertJsonPath('data.0.id', $participant->id)
        ->assertJsonPath('data.0.event.id', $event->id);

    $this->actingAs($user, 'api')->getJson('/api/my-events?scope=registered')
        ->assertOk()
        ->assertJsonPath('data.0.id', $participant->id)
        ->assertJsonPath('data.0.event.id', $event->id);
});

test('my events organized scope returns only owned events for admins', function () {
    $admin = cancelFlowAdmin();
    $otherAdmin = User::where('email', 'organizer@example.com')->firstOrFail();
    $ownedEvent = cancelFlowEvent(['organizer_id' => $admin->id, 'title' => 'Owned Event']);
    $otherEvent = cancelFlowEvent(['organizer_id' => $otherAdmin->id, 'title' => 'Other Event']);

    $response = $this->actingAs($admin, 'api')->getJson('/api/my-events?scope=organized')
        ->assertOk()
        ->assertJsonPath('data.0.id', $ownedEvent->id);

    expect(collect($response->json('data'))->pluck('id')->all())->toContain($ownedEvent->id)
        ->not->toContain($otherEvent->id);
});

test('regular users cannot request organized my events scope', function () {
    $this->actingAs(cancelFlowUser(), 'api')->getJson('/api/my-events?scope=organized')
        ->assertForbidden()
        ->assertJsonPath('success', false);
});

test('my events rejects unsupported scope values', function () {
    $this->actingAs(cancelFlowUser(), 'api')->getJson('/api/my-events?scope=all')
        ->assertStatus(422)
        ->assertJsonPath('success', false);
});

test('direct enrollment closes at exact registration deadline timestamp', function () {
    $user = cancelFlowUser();
    $event = cancelFlowEvent([
        'registration_deadline' => now()->subMinute(),
        'start_date' => now()->addDay(),
        'end_date' => now()->addDay()->addHour(),
    ]);

    $this->actingAs($user, 'api')->postJson("/api/events/{$event->id}/enroll")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Registration for this event has closed');
});

test('cart checkout closes at exact registration deadline timestamp', function () {
    $user = cancelFlowUser();
    $event = cancelFlowEvent([
        'registration_deadline' => now()->subMinute(),
        'start_date' => now()->addDay(),
        'end_date' => now()->addDay()->addHour(),
    ]);

    Cart::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user, 'api')->postJson('/api/carts/checkout')
        ->assertOk()
        ->assertJsonCount(0, 'data.enrolled')
        ->assertJsonPath('data.skipped.0.reason', 'Registration has closed');
});

test('auto absent scheduler does not change cancelled participants', function () {
    $user = cancelFlowUser();
    $event = cancelFlowEvent([
        'start_date' => now()->subHours(3),
        'end_date' => now()->subHour(),
        'registration_open' => now()->subDays(2),
        'registration_deadline' => now()->subHours(4),
    ]);

    EventAbsentSchedule::create([
        'event_id' => $event->id,
        'run_at' => now()->subMinute(),
    ]);

    $participant = EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'status' => 'cancelled',
        'unique_code' => null,
        'cancelled_at' => now(),
    ]);

    Artisan::call('events:process-absent-schedules');

    expect($participant->fresh()->status)->toBe('cancelled');
});
