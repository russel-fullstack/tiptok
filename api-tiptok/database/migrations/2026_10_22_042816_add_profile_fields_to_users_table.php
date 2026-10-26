<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // À n'exécuter que pour les colonnes absentes de ta base actuelle
            $table->string('username', 30)->unique()->after('name');
            $table->string('avatar_url')->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('avatar_url'); // chemin disque (ch. 12)
            $table->string('bio', 300)->nullable()->after('avatar_path');
            $table->string('role', 20)->default('user')->after('bio');       // user | moderator | admin (ch. 13)
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'avatar_url', 'avatar_path', 'bio', 'role']);
        });
    }
};
