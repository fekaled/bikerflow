<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('paid_at');
            $table->string('failure_reason', 500)->nullable()->after('failed_at');
            $table->unsignedInteger('retry_count')->default(0)->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'failure_reason', 'failed_at']);
        });
    }
};
