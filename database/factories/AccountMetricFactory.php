<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccountMetric;
use App\Models\ConnectedAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<AccountMetric> */
class AccountMetricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'connected_account_id' => ConnectedAccount::factory(),
            'captured_at' => Date::now(),
            'followers' => fake()->numberBetween(0, 10000),
            'following' => fake()->numberBetween(0, 1000),
            'posts_count' => fake()->numberBetween(0, 500),
            'raw' => null,
        ];
    }
}
