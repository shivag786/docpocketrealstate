<?php

namespace Database\Factories;

use App\Enums\MemberStatus;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    /**
     * Monotonic per-process counter.
     *
     * It deliberately never resets: RefreshDatabase truncates between tests, so
     * a counter that restarted would reissue codes and collide with rows built
     * earlier in the same batch. Gaps are harmless.
     */
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = ++self::$sequence;

        return [
            'member_code' => config('members.code.prefix', 'RS').$sequence,
            'sequence_number' => $sequence,
            'name' => fake()->name(),
            'mobile' => '9'.fake()->unique()->numerify('#########'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'sponsor_id' => null,
            'joining_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'status' => MemberStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MemberStatus::Inactive,
        ]);
    }

    public function sponsoredBy(Member $sponsor): static
    {
        return $this->state(fn (array $attributes) => [
            'sponsor_id' => $sponsor->id,
        ]);
    }

    public function root(): static
    {
        return $this->state(fn (array $attributes) => [
            'sponsor_id' => null,
        ]);
    }
}
