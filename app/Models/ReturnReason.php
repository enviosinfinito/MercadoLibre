<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code',
    'ml_reason_id',
    'label',
    'group',
])]
class ReturnReason extends Model
{
}
