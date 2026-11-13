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
        Schema::table('users', function (Blueprint $table) {
           $table->string('phone_country_code', 10)->nullable()->after('two_factor_recovery_codes');
            $table->string('phone_number', 30)->nullable()->after('phone_country_code');
            $table->string('avatar_path')->nullable()->after('phone_number');
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone_country_code',
                'phone_number',
                'avatar_path',
               ]);
        });
    }
};
