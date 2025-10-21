<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $fillable = [
        'user_id',
        'filename',
        'original_filename',
        'filesize',
        'mime_type',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
