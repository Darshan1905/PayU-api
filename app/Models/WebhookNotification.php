<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookNotification extends Model
{
    protected $fillable = [
        'collect_ref', 'transaction_id', 'status', 'status_message',
        'utr', 'payment_mode', 'request_amount', 'remarks',
        'raw_payload', 'processed', 'forwarded_to',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'processed' => 'boolean',
        'request_amount' => 'decimal:2',
    ];
}
