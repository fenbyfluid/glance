<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_admin' => false,
            'can_share' => false,
        ];
    }

    /**
     * Indicate that the model should be a pending user.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'username' => null,
            'password' => null,
            'remember_token' => null,
            'is_admin' => false,
            'can_share' => false,
        ]);
    }

    /**
     * Indicate that the user can create public shared links.
     */
    public function canShare(): static
    {
        return $this->state(fn (array $attributes) => [
            'can_share' => true,
        ]);
    }
}
