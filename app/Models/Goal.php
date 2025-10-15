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
        'account_allocation_id',
        'target_amount',
        'target_deadline',
        'goal_name',
        'is_first',
        'is_goal_achieved',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'target_deadline' => 'date',
        'is_first' => 'boolean',
        'is_goal_achieved' => 'boolean',
    ];

    public function user() 
    {
        return $this->belongsTo(User::class);
    }

    public function accountAllocation()
    {
        return $this->belongsTo(AccountAllocation::class, 'account_allocation_id');
    }

    // Helper to get current amount from account allocation
    public function getCurrentAmount()
    {
        return $this->accountAllocation ? $this->accountAllocation->balance_per_type : 0;
    }

    // Helper to get account through allocation
    public function getAccount()
    {
        return $this->accountAllocation ? $this->accountAllocation->account : null;
    }

    // Calculate progress percentage
    public function getProgressPercentage()
    {
        if ($this->target_amount <= 0) return 0;
        
        $currentAmount = $this->getCurrentAmount();
        $progress = ($currentAmount / $this->target_amount) * 100;
        
        return min($progress, 100); // Cap at 100%
    }

    // Check if goal is achieved
    public function isAchieved()
    {
        return $this->getCurrentAmount() >= $this->target_amount;
    }
}
