<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Account;

class Income extends Model
{
    protected $table = 'incomes';

    protected $fillable = [
        'user_id',
        'account_id',
        'amount',
        'actual_amount',
        'note',
        'received_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'received_date' => 'date',
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
