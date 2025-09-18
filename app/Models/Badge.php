<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\UserBadge;

class Badge extends Model
{
    protected $table = 'badges';

    protected $fillable = [
        'name',
        'description',
        'icon',
    ];

    public function userBadges()
    {
        return $this->hasMany(UserBadge::class, 'badge_id');
    }
}
