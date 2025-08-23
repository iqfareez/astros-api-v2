<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Astronaut extends Model
{
    protected $fillable = [
        'name',
        'craft',
        'imageUrl',
    ];
}
