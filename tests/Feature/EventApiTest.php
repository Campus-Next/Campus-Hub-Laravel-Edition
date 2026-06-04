<?php

use App\Models\Event;
use App\Models\Image;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\EventSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([
        PermissionSeeder::class,
        UserSeeder::class,
        CategorySeeder::class,
        EventSeeder::class,
    ]);
    useCleanClamdScanner();
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

test('admins can create events with arbitrary attachments', function () {
    Storage::fake('public');
    $admin = User::where('email', 'organizer@example.com')->first();
    $attachment = UploadedFile::fake()->create('payload.sh', 4, 'text/x-shellscript');

    $response = $this->actingAs($admin, 'api')->post('/api/events', [
        'title' => 'Event With Attachment',
        'description' => 'A new event with a document attachment',
        'start_date' => now()->addDays(10)->format('Y-m-d H:i:s'),
        'end_date' => now()->addDays(10)->addHours(2)->format('Y-m-d H:i:s'),
        'location' => 'Online',
        'attachment' => $attachment,
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.attachment_name', 'payload.sh');

    $event = Event::where('title', 'Event With Attachment')->firstOrFail();

    expect($event->attachment_path)->toStartWith('event_attachments/');
    expect($event->attachment_path)->toEndWith('.sh');
    Storage::disk('public')->assertExists($event->attachment_path);
});

test('admins can create events with arbitrary clean image uploads', function () {
    Storage::fake('public');
    $admin = User::where('email', 'organizer@example.com')->first();
    $image = UploadedFile::fake()->create('payload.sh', 4, 'text/x-shellscript');

    $response = $this->actingAs($admin, 'api')->post('/api/events', [
        'title' => 'Event With Arbitrary Image',
        'description' => 'A new event with a non-image poster payload',
        'start_date' => now()->addDays(10)->format('Y-m-d H:i:s'),
        'end_date' => now()->addDays(10)->addHours(2)->format('Y-m-d H:i:s'),
        'location' => 'Online',
        'image' => $image,
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true);

    $path = $response->json('data.images.0.path');

    expect($path)->toStartWith('events/');
    expect($path)->toEndWith('.sh');
    Storage::disk('public')->assertExists($path);
});

test('admins can update events with arbitrary clean image uploads', function () {
    Storage::fake('public');
    $admin = User::where('email', 'organizer@example.com')->first();
    $event = Event::factory()->create([
        'organizer_id' => $admin->id,
    ]);
    $image = UploadedFile::fake()->create('poster.txt', 2, 'text/plain');

    $response = $this->actingAs($admin, 'api')->patch("/api/events/{$event->id}", [
        'image' => $image,
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true);

    $path = $response->json('data.images.0.path');

    expect($path)->toStartWith('events/');
    expect($path)->toEndWith('.txt');
    Storage::disk('public')->assertExists($path);
});

test('infected image uploads are rejected, quarantined, logged, and rolled back', function () {
    Storage::fake('public');
    $quarantinePath = useTestClamavQuarantinePath();
    useFoundClamdScanner();

    $admin = User::where('email', 'organizer@example.com')->first();
    $imageCountBefore = Image::count();
    $image = UploadedFile::fake()->createWithContent(
        'eicar.txt',
        'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
    );

    $response = $this->actingAs($admin, 'api')->post('/api/events', [
        'title' => 'Infected Event',
        'description' => 'A new event with an infected poster payload',
        'start_date' => now()->addDays(10)->format('Y-m-d H:i:s'),
        'end_date' => now()->addDays(10)->addHours(2)->format('Y-m-d H:i:s'),
        'location' => 'Online',
        'image' => $image,
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Malware detected');

    $this->assertDatabaseMissing('events', [
        'title' => 'Infected Event',
    ]);
    expect(Image::count())->toBe($imageCountBefore);

    expect(File::files($quarantinePath.DIRECTORY_SEPARATOR.'files'))->toHaveCount(1);
    expect(File::get($quarantinePath.DIRECTORY_SEPARATOR.'clamav-upload.log'))->toContain('Eicar-Test-Signature');
});

test('scanner unavailable rejects image updates without deleting old image', function () {
    Storage::fake('public');
    useTestClamavQuarantinePath();
    useUnavailableClamdScanner();

    $admin = User::where('email', 'organizer@example.com')->first();
    $event = Event::factory()->create([
        'organizer_id' => $admin->id,
        'title' => 'Original Event',
    ]);
    $oldPath = 'events/old.txt';
    Storage::disk('public')->put($oldPath, 'old');
    Image::create([
        'event_id' => $event->id,
        'path' => $oldPath,
    ]);

    $response = $this->actingAs($admin, 'api')->patch("/api/events/{$event->id}", [
        'title' => 'Updated Event',
        'image' => UploadedFile::fake()->create('new.txt', 2, 'text/plain'),
    ]);

    $response->assertStatus(503)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'ClamAV scanner unavailable');

    $this->assertDatabaseHas('events', [
        'id' => $event->id,
        'title' => 'Original Event',
    ]);
    $this->assertDatabaseHas('images', [
        'event_id' => $event->id,
        'path' => $oldPath,
    ]);
    Storage::disk('public')->assertExists($oldPath);
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

test('admins can replace and remove event attachments', function () {
    Storage::fake('public');
    $admin = User::where('email', 'organizer@example.com')->first();
    $event = Event::factory()->create([
        'organizer_id' => $admin->id,
        'attachment_path' => 'event_attachments/old.pdf',
        'attachment_name' => 'old.pdf',
    ]);
    Storage::disk('public')->put('event_attachments/old.pdf', 'old');

    $newAttachment = UploadedFile::fake()->create('replace.sh', 3, 'text/x-shellscript');

    $this->actingAs($admin, 'api')->patch("/api/events/{$event->id}", [
        'attachment' => $newAttachment,
    ])->assertOk()
        ->assertJsonPath('data.attachment_name', 'replace.sh');

    $event->refresh();
    Storage::disk('public')->assertMissing('event_attachments/old.pdf');
    Storage::disk('public')->assertExists($event->attachment_path);

    $path = $event->attachment_path;

    $this->actingAs($admin, 'api')->patchJson("/api/events/{$event->id}", [
        'remove_attachment' => true,
    ])->assertOk()
        ->assertJsonPath('data.attachment_path', null)
        ->assertJsonPath('data.attachment_name', null);

    Storage::disk('public')->assertMissing($path);
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
