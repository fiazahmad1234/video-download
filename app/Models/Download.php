<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Download extends Model
{
    protected $fillable = [
        'token',
        'url',
        'title',
        'thumbnail',
        'status',
        'progress',
        'format_id',
        'format_label',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'error',
        'meta',
        'expires_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'expires_at' => 'datetime',
    ];
}