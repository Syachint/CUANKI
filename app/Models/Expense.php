<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Account;
use App\Models\ExpenseCategories;

class Expense extends Model
{
    protected $table = 'expenses';

    protected $fillable = [
        'user_id',
        'account_id',
        'expense_category_id',
        'amount',
        'note',
        'expense_date',
        'is_manual',
        'frequency',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(user::class, 'user_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategories::class, 'expense_category_id');
    }
}
