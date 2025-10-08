<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'google_id',
        'email',
        'password',
        'name',
        'username',
        'age',
        'origin_id', // ID kota dari API eksternal
        'status',
    ];

    protected $hidden = [
        'google_id',
        'email',
        'password',
        'remember_token',
    ];

    // Relasi contoh: user punya banyak akun
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    // Optional: accessor buat munculin nama kota dari API
    // (nanti bisa dipanggil di controller/service)
    public function origin()
    {
        return $this->belongsTo(Origin::class, 'origin_id', 'id');
    }
}
