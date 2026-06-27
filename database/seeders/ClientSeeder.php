<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        Client::updateOrCreate(
            ['api_key' => 'payu-pavokart-demo-key'],
            [
                'name' => 'Pavokart demo client',
                'callback_url' => 'https://pavokart.com/wp-json/payu/v1/callback/success',
                'is_active' => true,
            ]
        );

        $this->command->info('Demo client API key: payu-pavokart-demo-key');
    }
}
