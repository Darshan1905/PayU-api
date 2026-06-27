<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = ['name', 'api_key', 'callback_url', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public static function findByApiKey(string $apiKey): ?self
    {
        return static::where('api_key', $apiKey)->where('is_active', true)->first();
    }
}
