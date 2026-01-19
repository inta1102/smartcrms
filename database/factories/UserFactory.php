<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name'     => $this->faker->name(),
            'email'    => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('password'), // aman untuk test
            'level'    => 'STAFF',            // default role kamu
        ];
    }

    // helper biar gampang di test
    public function level(string $level): static
    {
        return $this->state(fn () => ['level' => $level]);
    }
}
