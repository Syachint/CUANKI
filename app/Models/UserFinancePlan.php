<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFinancePlan extends Model
{
    protected $table = 'user_finance_plan';

    protected $fillable = [
        'user_id',
        'monthly_income',
        'income_date',
        'saving_target_amount',
        'emergency_target_amount',
        'saving_target_duration',
    ];

    protected $casts = [
        'monthly_income' => 'decimal:2',
        'saving_target_amount' => 'decimal:2',
        'emergency_target_amount' => 'decimal:2',
    ];
}
