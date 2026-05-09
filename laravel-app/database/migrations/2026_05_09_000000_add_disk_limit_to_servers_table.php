<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->integer('disk_limit_gb')->nullable()->after('limit_slots');
        });
    }

    public function down()
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('disk_limit_gb');
        });
    }
};