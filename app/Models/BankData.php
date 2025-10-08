<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankData extends Model
{
    protected $table = 'bank_data';

    protected $fillable = [
        'code_name',
        'bank_name',
    ];
}
