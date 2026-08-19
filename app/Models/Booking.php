<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'booking';

    protected $guarded = [];

    protected $casts = [
        'total_amount' => 'float',
        'cybersource_payment_data' => 'array',
    ];

    public $timestamps = false;
}
