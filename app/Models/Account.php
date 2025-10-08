<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\User;
use App\Models\BankData;
use App\Models\Budget;
use App\Models\Goal;
use App\Models\AccountAllocation;
use App\Models\Income;
use App\Models\Expense;

class Account extends Model
{
    use HasFactory;

    protected $table = 'accounts';

    protected $fillable = [
        'user_id',
        'bank_id',
        'account_name',
        'initial_balance',
        'current_balance',
    ];

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bank()
    {
        return $this->belongsTo(BankData::class, 'bank_id');
    }

    public function budgets()
    {
        return $this->hasOne(Budget::class, 'account_id');
    }

    public function goals()
    {
        return $this->hasMany(Goal::class, 'account_id');
    }

    public function allocations()
    {
        return $this->hasMany(AccountAllocation::class, 'account_id');
    }

    public function incomes()
    {
        return $this->hasMany(Income::class, 'account_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'account_id');
    }
}
