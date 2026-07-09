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

        Client::updateOrCreate(
            ['api_key' => 'mswipe-pavokart-demo-key'],
            [
                'name' => 'Pavokart Mswipe demo client',
                'callback_url' => 'https://pavokart.com/wp-json/mswipe/v1/callback',
                'is_active' => true,
            ]
        );

        $this->command->info('Demo API keys: payu-pavokart-demo-key, mswipe-pavokart-demo-key');
    }
}
