<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pix_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biker_id')->constrained('bikers')->cascadeOnDelete();
            $table->string('key_type', 20);
            $table->string('key_value');
            $table->string('account_holder_name')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['biker_id', 'key_type', 'key_value']);
            $table->index('biker_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pix_keys');
    }
};
