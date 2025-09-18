<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class CalendarLogs extends Model
{
    protected $table = 'calendar_logs';

    protected $fillable = [
        'user_id',
        'date',
        'planned_budget',
        'actual_expense',
        'carryover_saving',
        'is_ontrack',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
        'planned_budget' => 'decimal:2',
        'actual_expense' => 'decimal:2',
        'carryover_saving' => 'decimal:2',
        'is_ontrack' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
