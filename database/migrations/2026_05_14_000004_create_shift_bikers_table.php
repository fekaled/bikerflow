<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_bikers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('biker_id')->constrained('bikers')->cascadeOnDelete();
            $table->unsignedInteger('trips_count')->default(0);
            $table->decimal('biker_rate', 12, 2);
            $table->decimal('base_fee', 12, 2);
            $table->timestamps();

            $table->unique(['shift_id', 'biker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_bikers');
    }
};
