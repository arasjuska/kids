<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    protected $fillable = [
        'address_id',
        'name',
    ];

    public function address()
    {
        return $this->belongsTo(Address::class);
    }
}
