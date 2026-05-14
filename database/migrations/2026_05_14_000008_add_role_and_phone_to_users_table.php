<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            $table->string('role', 30)->default('admin')->after('phone_verified_at');
            $table->unsignedBigInteger('restaurant_id')->nullable()->after('role');
            $table->unsignedBigInteger('biker_id')->nullable()->after('restaurant_id');

            $table->foreign('restaurant_id')
                ->references('id')
                ->on('restaurants')
                ->nullOnDelete();

            $table->foreign('biker_id')
                ->references('id')
                ->on('bikers')
                ->nullOnDelete();

            $table->index('role');
        });

        // Make email and password nullable for phone-based auth
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['restaurant_id']);
            $table->dropForeign(['biker_id']);
            $table->dropIndex(['role']);
            $table->dropColumn([
                'phone',
                'phone_verified_at',
                'role',
                'restaurant_id',
                'biker_id',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
