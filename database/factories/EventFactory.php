<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('+7 days', '+1 year');
        $endDate = fake()->dateTimeBetween($startDate, (clone $startDate)->modify('+3 days'));
        $registrationOpen = fake()->dateTimeBetween('-1 month', 'now');
        $registrationDeadline = fake()->dateTimeBetween('now', (clone $startDate)->modify('-1 day'));

        return [
            // 'organizer_id' should be filled on seeder or upon creation,
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'location' => fake()->city(),
            'max_participants' => fake()->numberBetween(10, 1000),
            'registration_fee' => 0,
            'registration_open' => $registrationOpen,
            'registration_deadline' => $registrationDeadline,
        ];
    }
}
