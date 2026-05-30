<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ParticipantSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $events = Event::all();
        $users = User::role('user')->get();

        if ($events->isEmpty()) {
            $this->command->warn('No events found. Please run EventSeeder first.');
            return;
        }

        if ($users->isEmpty()) {
            $this->command->warn('No users found. Please run UserSeeder first.');
            return;
        }

        foreach ($users as $user) {
            $events->take(min(3, $events->count()))->each(function (Event $event) use ($user) {
                $participant = EventParticipant::firstOrNew([
                    'user_id' => $user->id,
                    'event_id' => $event->id,
                ]);

                $participant->status = 'registered';

                if (!$participant->unique_code) {
                    $participant->unique_code = EventParticipant::generateUniqueCode($event);
                }

                $participant->save();
            });
        }
    }
}
