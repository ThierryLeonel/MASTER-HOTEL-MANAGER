<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RoomCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Standard', 'Deluxe', 'Suite', 'Apartment']).' '.fake()->unique()->numerify('##'),
            'description' => fake()->sentence(),
            'max_occupancy' => fake()->numberBetween(1, 4),
            // Correction round (audited defect #6 / feature "approved
            // discounts on check-in"): check-in now server-validates that
            // agreed/quoted rate is not below the category's own
            // base_rate without an approved discount (see
            // DiscountApprovalService). This used to be a random value
            // from a higher set, completely independent of whatever
            // fixed rate_quoted/agreed_rate a test hardcoded elsewhere
            // (ReservationFactory defaults to 30000; many tests hardcode
            // 20000-35000) — a real, previously-latent flakiness risk
            // that would now make roughly three tests in four spuriously
            // trip the new discount-approval gate. Fixed at a low,
            // deterministic default well under every such hardcoded test
            // rate, so an ordinary test that never mentions discounts at
            // all keeps quoting/agreeing at or above the standard rate,
            // exactly as before. A test that specifically wants to assert
            // on the rate/category relationship already always overrides
            // base_rate explicitly (grep-verified) and is unaffected.
            'base_rate' => 10000,
            'is_active' => true,
        ];
    }
}
