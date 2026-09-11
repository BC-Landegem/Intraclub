<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gender = fake()->randomElement(Gender::cases());
        $playsCompetition = fake()->boolean(80);

        return [
            'first_name' => $gender === Gender::Female
                ? fake()->firstNameFemale()
                : fake()->firstNameMale(),
            'last_name' => fake()->lastName(),
            'gender' => $gender,
            'birth_date' => fake()->dateTimeBetween('-70 years', '-16 years')->format('Y-m-d'),
            'plays_competition' => $playsCompetition,
            'double_ranking' => $playsCompetition ? fake()->numberBetween(0, 12) : 0,
            'is_member' => true,
        ];
    }
}
