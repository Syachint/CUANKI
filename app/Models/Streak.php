<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class Streak extends Model
{
    protected $table = 'streaks';

    protected $fillable = [
        'user_id',
        'current_streak',
        'longest_streak',
        'last_submit_date',
        'streak_status',
    ];

    protected $casts = [
        'last_submit_date' => 'date',
        'streak_status' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
