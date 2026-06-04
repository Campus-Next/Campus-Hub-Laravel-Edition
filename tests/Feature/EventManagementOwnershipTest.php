<?php

use App\Models\Event;
use App\Models\EventLink;
use App\Models\EventParticipant;
use App\Models\Image;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([
        PermissionSeeder::class,
        UserSeeder::class,
    ]);
    useCleanClamdScanner();
});

function managementOwnerAdmin(): User
{
    return User::where('email', 'admin@example.com')->firstOrFail();
}

function managementOtherAdmin(): User
{
    return User::where('email', 'organizer@example.com')->firstOrFail();
}

function managementOwnedEvent(array $overrides = []): Event
{
    return Event::factory()->create([
        'organizer_id' => managementOwnerAdmin()->id,
        'registration_open' => now()->subDay(),
        'registration_deadline' => now()->addDay(),
        ...$overrides,
    ]);
}

function managementFakePng(string $name = 'poster.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=')
    );
}

test('admin cannot update or delete another organizers event', function () {
    $event = managementOwnedEvent();
    $otherAdmin = managementOtherAdmin();

    $this->actingAs($otherAdmin, 'api')->patchJson("/api/events/{$event->id}", [
        'title' => 'Hijacked title',
    ])->assertForbidden()
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'title' => $event->title,
    ]);

    $this->actingAs($otherAdmin, 'api')->deleteJson("/api/events/{$event->id}")
        ->assertForbidden()
        ->assertJsonPath('success', false);

    $this->assertDatabaseHas('events', [
        'id' => $event->id,
    ]);
});

test('admin cannot manage another organizers event links', function () {
    $event = managementOwnedEvent();
    $link = EventLink::create([
        'event_id' => $event->id,
        'title' => 'Original link',
        'url' => 'https://example.com/original',
    ]);
    $otherAdmin = managementOtherAdmin();

    $this->actingAs($otherAdmin, 'api')->postJson("/api/events/{$event->id}/links", [
        'title' => 'Injected link',
        'url' => 'https://example.com/injected',
    ])->assertForbidden();

    $this->actingAs($otherAdmin, 'api')->patchJson("/api/events/{$event->id}/links/{$link->id}", [
        'title' => 'Changed link',
    ])->assertForbidden();

    $this->actingAs($otherAdmin, 'api')->deleteJson("/api/events/{$event->id}/links/{$link->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('event_links', [
        'id' => $link->id,
        'title' => 'Original link',
    ]);
});

test('admin cannot manage another organizers participants or check ins', function () {
    $event = managementOwnedEvent();
    $participant = EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => User::where('email', 'user@example.com')->firstOrFail()->id,
        'status' => 'registered',
        'unique_code' => 'A1B2',
    ]);
    $otherAdmin = managementOtherAdmin();

    $this->actingAs($otherAdmin, 'api')->getJson("/api/events/{$event->id}/participants")
        ->assertForbidden();

    $this->actingAs($otherAdmin, 'api')->getJson("/api/events/{$event->id}/participants/{$participant->id}")
        ->assertForbidden();

    $this->actingAs($otherAdmin, 'api')->patchJson("/api/events/{$event->id}/participants/{$participant->id}", [
        'status' => 'attended',
    ])->assertForbidden();

    $this->actingAs($otherAdmin, 'api')->postJson("/api/events/{$event->id}/check-in", [
        'code' => 'A1B2',
    ])->assertForbidden();

    expect($participant->fresh()->status)->toBe('registered');
});

test('event owner can check in registered participants', function () {
    $event = managementOwnedEvent();
    $participant = EventParticipant::create([
        'event_id' => $event->id,
        'user_id' => User::where('email', 'user@example.com')->firstOrFail()->id,
        'status' => 'registered',
        'unique_code' => 'C3D4',
    ]);

    $this->actingAs(managementOwnerAdmin(), 'api')->postJson("/api/events/{$event->id}/check-in", [
        'code' => 'C3D4',
    ])->assertOk()
        ->assertJsonPath('data.id', $participant->id)
        ->assertJsonPath('data.status', 'attended');
});

test('admin cannot upload or delete another organizers event image', function () {
    Storage::fake('public');

    $event = managementOwnedEvent();
    $otherAdmin = managementOtherAdmin();

    $this->actingAs($otherAdmin, 'api')->post("/api/events/{$event->id}/image", [
        'image' => managementFakePng(),
    ])->assertForbidden();

    $this->assertDatabaseCount('images', 0);

    $upload = $this->actingAs(managementOwnerAdmin(), 'api')->post("/api/events/{$event->id}/image", [
        'image' => UploadedFile::fake()->create('payload.sh', 4, 'text/x-shellscript'),
    ])->assertCreated();

    $imageId = $upload->json('data.id');
    $path = $upload->json('data.path');

    expect(str_starts_with($path, 'events/'))->toBeTrue();
    expect($path)->toEndWith('.sh');
    Storage::disk('public')->assertExists($path);

    $this->actingAs($otherAdmin, 'api')->deleteJson("/api/images/{$imageId}")
        ->assertForbidden();

    $this->assertDatabaseHas('images', [
        'id' => $imageId,
    ]);

    $this->actingAs(managementOwnerAdmin(), 'api')->deleteJson("/api/images/{$imageId}")
        ->assertOk();

    $this->assertDatabaseMissing('images', [
        'id' => $imageId,
    ]);
    Storage::disk('public')->assertMissing($path);
});

test('deleting an event removes image files and database rows', function () {
    Storage::fake('public');

    $event = managementOwnedEvent();
    $path = 'events/delete-me.jpg';
    Storage::disk('public')->put($path, 'image-bytes');
    $image = Image::create([
        'event_id' => $event->id,
        'path' => $path,
    ]);

    $this->actingAs(managementOwnerAdmin(), 'api')->deleteJson("/api/events/{$event->id}")
        ->assertOk();

    $this->assertDatabaseMissing('images', [
        'id' => $image->id,
    ]);
    Storage::disk('public')->assertMissing($path);
});
