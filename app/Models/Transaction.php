<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'client_id', 'collect_ref', 'user_ref', 'transaction_id', 'amount',
        'status', 'status_message', 'utr', 'payment_mode', 'callback_url', 'raw_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_response' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public static function findByRef(?string $collectRef, ?string $transactionId): ?self
    {
        if (($collectRef ?? '') !== '') {
            $t = static::where('collect_ref', $collectRef)->first();
            if ($t) {
                return $t;
            }
        }
        if (($transactionId ?? '') !== '') {
            return static::where('transaction_id', $transactionId)->first();
        }

        return null;
    }
}
