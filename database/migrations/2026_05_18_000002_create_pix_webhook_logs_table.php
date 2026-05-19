<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pix_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_transaction_id', 255)->nullable()->index('idx_webhook_logs_gateway_txn_id');
            $table->json('payload')->nullable();
            $table->string('status', 20);
            $table->text('error_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('received_at')->nullable()->index('idx_webhook_logs_received_at');
            $table->timestamps();

            $table->index('status', 'idx_webhook_logs_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pix_webhook_logs');
    }
};
