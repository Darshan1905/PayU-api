<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('collect_ref', 64)->index();
            $table->string('user_ref', 64)->nullable();
            $table->string('transaction_id', 128)->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->string('status')->nullable();
            $table->string('status_message')->nullable();
            $table->string('utr')->nullable();
            $table->string('payment_mode')->nullable();
            $table->string('callback_url')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
