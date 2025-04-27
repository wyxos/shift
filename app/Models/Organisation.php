<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organisation extends Model
{
    /** @use HasFactory<\Database\Factories\OrganisationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    // user
    public function author()
    {
        return $this->belongsTo(User::class);
    }
}
