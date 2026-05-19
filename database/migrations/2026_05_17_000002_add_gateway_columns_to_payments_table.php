<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4B: Add gateway tracking columns to payments.
     *
     * - gateway_transaction_id: External bank API transaction identifier
     * - gateway_status: Last known status from the gateway
     *
     * AC-4B-01, AC-4B-02, AC-4B-03, AC-4B-06
     *
     * @see docs/plans/phase-4b-pix-payment-execution.md
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway_transaction_id', 255)->nullable()->after('retry_count');
            $table->string('gateway_status', 50)->nullable()->after('gateway_transaction_id');
            $table->index('gateway_transaction_id', 'idx_payments_gateway_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_gateway_transaction_id');
            $table->dropColumn(['gateway_transaction_id', 'gateway_status']);
        });
    }
};
