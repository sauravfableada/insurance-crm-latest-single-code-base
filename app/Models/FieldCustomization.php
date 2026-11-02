<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FieldCustomization extends Model
{
    use HasFactory;

    protected $table = 'field_customizations';

    protected $fillable = [
        'module',
        'field_name',
        'custom_label',
        'is_visible',
        'is_required',
        'validation_message',
        'display_order',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'is_required' => 'boolean',
        'display_order' => 'integer',
    ];
}

