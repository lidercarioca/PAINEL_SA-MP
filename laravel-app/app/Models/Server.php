<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\User;
use App\Models\Plan;

class Server extends Model
{
    protected $fillable = [
        'name',
        'ip',
        'port',
        'password',
        'type',
        'engine',
        'status',
        'folder',
        'owner_id',
        'plan_id',
        'game_mode',
        'limit_ram',
        'limit_slots',
        'disk_limit_gb',
        'pid',
        'auto_restart_interval_hours',
        'auto_restart_on_crash',
        'auto_restart_on_offline',
        'last_auto_restart_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'disk_limit_gb' => 'integer',
        'auto_restart_interval_hours' => 'integer',
        'auto_restart_on_crash' => 'boolean',
        'auto_restart_on_offline' => 'boolean',
        'last_auto_restart_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
