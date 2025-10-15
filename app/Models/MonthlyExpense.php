<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyExpense extends Model
{
    protected $table = 'monthly_expenses';

    protected $fillable = [
        'user_id',
        'expense_category_id',
        'total_amount',
        'current_amount',
        'used_amount',
        'month',
        'year',
        'is_active',
        'note',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the user that owns the monthly expense
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the expense category
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategories::class, 'expense_category_id');
    }

    /**
     * Get usage percentage
     */
    public function getUsagePercentageAttribute(): float
    {
        if ($this->total_amount <= 0) return 0;
        return ($this->used_amount / $this->total_amount) * 100;
    }

    /**
     * Get remaining budget
     */
    public function getRemainingBudgetAttribute(): float
    {
        return $this->current_amount;
    }

    /**
     * Check if budget is exceeded
     */
    public function isOverBudget(): bool
    {
        return $this->current_amount < 0;
    }

    /**
     * Add expense to this monthly budget
     */
    public function addExpense(float $amount): bool
    {
        $this->used_amount += $amount;
        $this->current_amount -= $amount;
        return $this->save();
    }

    /**
     * Scope for current month
     */
    public function scopeCurrentMonth($query)
    {
        $now = now();
        return $query->where('month', $now->month)
                    ->where('year', $now->year);
    }

    /**
     * Scope for active monthly expenses
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}