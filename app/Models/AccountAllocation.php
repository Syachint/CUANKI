<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Account;

class AccountAllocation extends Model
{
    protected $table = 'accounts_allocation';

    protected $fillable = [
        'account_id',
        'type',
        'balance_per_type',
        'allocation_date',
    ];

    protected $casts = [
        'balance_per_type' => 'decimal:2',
        'allocation_date' => 'date',
    ];

    public function user()
    {
        return $this->hasOneThrough(User::class, Account::class, 'id', 'id', 'account_id', 'user_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
