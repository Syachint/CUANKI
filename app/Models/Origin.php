<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class Origin extends Model
{
    protected $table = 'origin';

    protected $fillable = [
        'user_id',
        'city_province',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
