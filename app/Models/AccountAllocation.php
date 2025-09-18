<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Account;

class AccountAllocation extends Model
{
    protected $table = 'accounts_allocation';

    protected $fillable = [
        'user_id',
        'account_id',
        'type',
        'balance_per_type',
    ];

    protected $casts = [
        'balance_per_type' => 'decimal:2',
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
