<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Place extends Model
{
    protected $fillable = [
        'address_id',
        'name',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn ($value) => is_string($value) || $value === null
                ? TextNormalizer::toNfc($value)
                : TextNormalizer::toNfc((string) $value)
        );
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }
}
