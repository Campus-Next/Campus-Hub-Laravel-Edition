<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;

class CartSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::role('user')->get();
        $events = Event::all();

        if ($users->isEmpty() || $events->isEmpty()) {
            return;
        }

        // Ambil maksimal 5 user untuk di-seed cart-nya
        foreach ($users->take(5) as $user) {
            $availableEvents = $events->reject(
                fn (Event $event) => $user->participants()->where('event_id', $event->id)->exists(),
            );

            if ($availableEvents->isEmpty()) {
                continue;
            }

            // Berikan 1-3 acara acak ke dalam cart mereka
            $randomEvents = $availableEvents->take(min(2, $availableEvents->count()));
            
            foreach ($randomEvents as $event) {
                Cart::firstOrCreate([
                    'user_id' => $user->id,
                    'event_id' => $event->id,
                ]);
            }
        }
    }
}
