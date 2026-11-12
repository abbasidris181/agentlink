<?php

namespace Database\Factories;

use App\AdminAccess;
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
            'first_name' => fake()->firstName(),
            'surname' => fake()->lastName(),
            'name' => fn (array $attributes): string => $attributes['first_name'].' '.$attributes['surname'],
            'phone' => fake()->numerify('+1555#######'),
            'business' => fake()->company(),
            'address' => fake()->address(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function administrator(): static
    {
        return $this->state(fn (array $attributes): array => ['is_admin' => true, 'role' => 'super_admin', 'is_active' => true]);
    }

    /** @param list<string> $permissions */
    public function staff(array $permissions = []): static
    {
        return $this->state(fn (array $attributes): array => ['role' => 'staff', 'staff_permissions' => AdminAccess::withDependencies($permissions)]);
    }

    public function agent(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => 'agent']);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
