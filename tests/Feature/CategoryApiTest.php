<?php

use App\Models\Category;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;

beforeEach(function () {
    $this->seed([
        PermissionSeeder::class,
        UserSeeder::class,
    ]);
});

test('public users can list categories', function () {
    Category::create([
        'name' => 'Seminar',
        'slug' => 'seminar',
        'description' => 'Academic seminar events',
    ]);

    $response = $this->getJson('/api/categories');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.name', 'Seminar')
        ->assertJsonPath('data.0.slug', 'seminar');
});

test('public users can view a category', function () {
    $category = Category::create([
        'name' => 'Workshop',
        'slug' => 'workshop',
        'description' => 'Hands-on learning events',
    ]);

    $response = $this->getJson("/api/categories/{$category->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Workshop')
        ->assertJsonPath('data.description', 'Hands-on learning events');
});

test('public users cannot create categories', function () {
    $response = $this->postJson('/api/categories', [
        'name' => 'Competition',
    ]);

    $response->assertUnauthorized();
});

test('regular users cannot write categories', function () {
    $category = Category::create([
        'name' => 'Seminar',
        'slug' => 'seminar',
    ]);
    $user = User::where('email', 'user@example.com')->first();

    $this->actingAs($user, 'api')->postJson('/api/categories', [
        'name' => 'Competition',
    ])->assertForbidden();

    $this->actingAs($user, 'api')->patchJson("/api/categories/{$category->id}", [
        'name' => 'Student Talk',
    ])->assertForbidden();

    $this->actingAs($user, 'api')->deleteJson("/api/categories/{$category->id}")
        ->assertForbidden();
});

test('admins can create categories', function () {
    $user = User::where('email', 'admin@example.com')->first();

    $response = $this->actingAs($user, 'api')->postJson('/api/categories', [
        'name' => 'Competition',
        'description' => 'Campus competition events',
    ]);

    $response->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Competition')
        ->assertJsonPath('data.slug', 'competition');

    $this->assertDatabaseHas('categories', [
        'name' => 'Competition',
        'slug' => 'competition',
    ]);
});

test('category names must be unique', function () {
    Category::create([
        'name' => 'Seminar',
        'slug' => 'seminar',
    ]);

    $user = User::where('email', 'admin@example.com')->first();

    $response = $this->actingAs($user, 'api')->postJson('/api/categories', [
        'name' => 'Seminar',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

test('admins can update categories and regenerate slugs', function () {
    $category = Category::create([
        'name' => 'Seminar',
        'slug' => 'seminar',
    ]);
    $user = User::where('email', 'admin@example.com')->first();

    $response = $this->actingAs($user, 'api')->patchJson("/api/categories/{$category->id}", [
        'name' => 'Student Talk',
        'description' => 'Student speaker sessions',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Student Talk')
        ->assertJsonPath('data.slug', 'student-talk')
        ->assertJsonPath('data.description', 'Student speaker sessions');

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Student Talk',
        'slug' => 'student-talk',
    ]);
});

test('admins can delete categories', function () {
    $category = Category::create([
        'name' => 'Workshop',
        'slug' => 'workshop',
    ]);
    $user = User::where('email', 'admin@example.com')->first();

    $response = $this->actingAs($user, 'api')->deleteJson("/api/categories/{$category->id}");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Category deleted successfully');

    $this->assertDatabaseMissing('categories', [
        'id' => $category->id,
    ]);
});
