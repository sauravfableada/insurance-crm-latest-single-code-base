<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'discount_percent',
        'final_price',
        'duration',
        'start_date',
        'end_date',
        'subtitle',
        'user_limit',
        'branch_limit',
        'storage_limit',
        'is_active',
        'sub_branch_id',
        'features',
        'total_amount',
        'total_price',
        'renewal_price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'final_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total_price' => 'decimal:2',
        'renewal_price' => 'decimal:2',
        'user_limit' => 'integer',
        'branch_limit' => 'integer',
        'storage_limit' => 'integer',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'features' => 'array',
    ];

    public function users()
    {
        return $this->hasMany(User::class, 'plan_id');
    }
}

