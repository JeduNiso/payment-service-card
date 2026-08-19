<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

class Payment extends Model
{
    protected $table = 'payments';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'state' => 'string',
        'updated_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (Schema::hasTable('redirect_payment')) {
            $this->table = 'redirect_payment';
        } elseif (Schema::hasTable('redirect_payments')) {
            $this->table = 'redirect_payments';
        }
    }

    public function getCodeAttribute(): ?string
    {
        return $this->attributes['bookCode']
            ?? $this->attributes['code']
            ?? $this->attributes['payment_code']
            ?? null;
    }

    public function getPaymentCodeAttribute(): ?string
    {
        return $this->getCodeAttribute();
    }

    public function getTransactionIdAttribute(): ?string
    {
        return $this->attributes['extId']
            ?? $this->attributes['transaction_id']
            ?? $this->attributes['reference']
            ?? null;
    }

    public function getReferenceAttribute(): ?string
    {
        return $this->attributes['extId']
            ?? $this->attributes['reference']
            ?? $this->attributes['transaction_id']
            ?? null;
    }

    public function getCustomerUrlAttribute(): ?string
    {
        return $this->attributes['customer_url']
            ?? $this->attributes['redirect_url']
            ?? null;
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'payment_user', 'redirect_payment_id', 'auth_user_id');
    }
}
