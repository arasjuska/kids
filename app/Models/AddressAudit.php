<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddressAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'address_id',
        'user_id',
        'action',
        'changed_fields',
        'override_reason',
        'created_at',
    ];

    protected $casts = [
        'changed_fields' => 'array',
        'created_at' => 'datetime',
    ];

    public function address()
    {
        return $this->belongsTo(Address::class);
    }
}
