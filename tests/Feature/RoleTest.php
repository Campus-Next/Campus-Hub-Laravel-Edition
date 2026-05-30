<?php

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\UserSeeder;

beforeEach(function () {
    $this->seed([
        PermissionSeeder::class,
        UserSeeder::class,
    ]);
});

test('has admin and user role', function () {
    $this->assertDatabaseHas('roles', [
        'name' => 'admin',
    ]);
    $this->assertDatabaseHas('roles', [
        'name' => 'user',
    ]);
});

test('seeded user has roles', function () {
    // Check User Existence
    $this->assertDatabaseHas('users', [
        'email' => 'admin@example.com',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'organizer@example.com',
    ]);

    $this->assertDatabaseHas('users', [
        'email' => 'user@example.com',
    ]);

    // Check User has correct roles
    expect(User::where('email', 'admin@example.com')->first()->hasRole('admin'))->toBeTrue();
    expect(User::where('email', 'organizer@example.com')->first()->hasRole('admin'))->toBeTrue();
    expect(User::where('email', 'user@example.com')->first()->hasRole('user'))->toBeTrue();
});

test('new user have default role `user`', function () {
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    // Refresh
    $user->refresh();

    // Check User Creation
    expect($user)->not->toBeNull();

    // Check User does have atleast 1 role
    expect($user->getRoleNames())->toHaveCount(1);
    
    // Check User has correct role
    expect($user->hasRole('user'))->toBeTrue();
});
