<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->integer('auto_restart_interval_hours')->nullable()->after('limit_slots');
            $table->boolean('auto_restart_on_crash')->default(false)->after('auto_restart_interval_hours');
            $table->boolean('auto_restart_on_offline')->default(false)->after('auto_restart_on_crash');
            $table->timestamp('last_auto_restart_at')->nullable()->after('auto_restart_on_offline');
        });
    }

    public function down()
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'auto_restart_interval_hours',
                'auto_restart_on_crash',
                'auto_restart_on_offline',
                'last_auto_restart_at',
            ]);
        });
    }
};
