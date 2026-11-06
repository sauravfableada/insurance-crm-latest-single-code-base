<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FamilyGroupMember extends Model
{
    use SoftDeletes;

    protected $table = 'family_group_members';

    protected $fillable = [
        'family_group_id',
        'member_name',
        'relation',
        'contact',
        'dob',
        'anniversary',
        'remarks',
        'created_by',
        'modified_by',
        'deleted_by',
    ];

    public function contactForHolder(?Customer $holder): ?string
    {
        if (filled($this->contact)) return $this->contact;
        $normalize = fn ($value) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $value)));
        if ($holder && in_array($normalize($this->relation), ['self', 'proposer', 'policy holder', 'policyholder'], true)
            && $normalize($this->member_name) === $normalize($holder->name)) {
            return $holder->phone ?: null;
        }
        return null;
    }

    // Member belongs to a group
    public function group()
    {
        return $this->belongsTo(FamilyGroup::class, 'family_group_id');
    }

    // User tracking relations
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function modifier()
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    public function deleter()
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}