<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Account;

class Budget extends Model
{
    protected $table = 'budgets';

    protected $fillable = [
        'user_id',
        'account_id',
        'daily_budget',
        'initial_daily_budget',
        'daily_saving',
    ];

    protected $casts = [
        'daily_budget' => 'decimal:2',
        'initial_daily_budget' => 'decimal:2',
        'daily_saving' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
