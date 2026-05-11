<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'     => User::factory(),
            'amount'      => fake()->randomElement([2900, 9900, 49900]),
            'currency'    => 'usd',
            'status'      => 'succeeded',
            'description' => fake()->sentence(4),
        ];
    }
}
