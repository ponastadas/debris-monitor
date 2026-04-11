<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        return [
            'user_id'          => User::factory(),
            'name'             => 'Test Key',
            'key'              => ApiKey::generate(),
            'tier'             => 'free',
            'daily_limit'      => 100,
            'webhooks_enabled' => false,
            'satellite_limit'  => 5,
        ];
    }
}
