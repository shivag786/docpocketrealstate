<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Member;
use App\Models\Property;
use App\Models\RegistrySale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrySale>
 */
class RegistrySaleFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d');

        return [
            'member_id' => Member::factory(),
            // Resolved in configure() so the property and project always agree.
            'property_id' => Property::factory(),
            'project_id' => null,
            'registry_reference' => 'REG-'.str_pad((string) (++self::$sequence), 6, '0', STR_PAD_LEFT),
            'registry_date' => $date,
            'sale_date' => $date,
            'sqft' => fake()->randomFloat(2, 100, 5000),
            'status' => SaleStatus::Approved,
            'notes' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (RegistrySale $sale) {
            // Keep project_id consistent with the property it belongs to; an
            // inconsistent pair is rejected by validation and by the service.
            if ($sale->project_id === null && $sale->property_id !== null) {
                $sale->project_id = Property::find($sale->property_id)?->project_id;
            }

            // Preserve the invariant the form enforces: a sale cannot be dated
            // after its registry. Overriding registry_date alone would otherwise
            // leave the factory's random sale_date in the future relative to it,
            // producing fixtures the application would never accept.
            if ($sale->sale_date > $sale->registry_date) {
                $sale->sale_date = $sale->registry_date;
            }
        });
    }

    public function forMember(Member $member): static
    {
        return $this->state(fn () => ['member_id' => $member->id]);
    }

    public function forProperty(Property $property): static
    {
        return $this->state(fn () => [
            'property_id' => $property->id,
            'project_id' => $property->project_id,
        ]);
    }

    public function sqft(float|string $sqft): static
    {
        return $this->state(fn () => ['sqft' => $sqft]);
    }

    /** Place the sale in a given 'YYYY-MM' period via its registry date. */
    public function inPeriod(string $period, int $day = 15): static
    {
        $date = $period.'-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);

        return $this->state(fn () => [
            'registry_date' => $date,
            'sale_date' => $date,
        ]);
    }
}
