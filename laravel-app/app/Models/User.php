<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'api_token',
        'blocked',
    ];

    protected $hidden = [
        'password',
        'api_token',
    ];

    protected $casts = [
        'blocked' => 'boolean',
    ];

    public function servers()
    {
        return $this->hasMany(Server::class, 'owner_id');
    }
}
