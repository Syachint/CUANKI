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
        'name',
        'age',
        'origin_id', // ID kota dari API eksternal
        'status',
    ];

    // Relasi contoh: user punya banyak akun
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    // Optional: accessor buat munculin nama kota dari API
    // (nanti bisa dipanggil di controller/service)
    public function getOriginNameAttribute()
    {
        // default: null
        $originName = null;

        if ($this->origin_id) {
            // TODO: ambil dari API kota (bisa pakai Http::get dsb.)
            // contoh dummy aja dulu
            $originName = "Nama Kota dari API untuk ID {$this->origin_id}";
        }

        return $originName;
    }
}
