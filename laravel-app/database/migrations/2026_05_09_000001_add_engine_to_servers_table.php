<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Adiciona coluna engine com valor padrão 'samp' para compatibilidade retroativa
            $table->enum('engine', ['samp', 'fivem'])->default('samp')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('engine');
        });
    }
};
