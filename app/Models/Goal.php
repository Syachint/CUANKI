<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Account;

class Goal extends Model
{
    protected $table = 'goals';

    protected $fillable = [
        'user_id',
        'account_id',
        'target_amount',
        'target_deadline',
        'goal_name',
        'goal_amount',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'target_deadline' => 'date',
        'goal_amount' => 'decimal:2',
    ];

    public function user() 
    {
        return $this->belongsTo(User::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
