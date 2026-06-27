<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('collect_ref')->nullable()->index();
            $table->string('transaction_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->string('status_message')->nullable();
            $table->string('utr')->nullable();
            $table->string('payment_mode')->nullable();
            $table->decimal('request_amount', 12, 2)->nullable();
            $table->string('remarks')->nullable();
            $table->json('raw_payload');
            $table->boolean('processed')->default(false);
            $table->string('forwarded_to')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_notifications');
    }
};
