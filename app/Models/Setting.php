<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['firm_name', 'firm_address', 'firm_email', 'contact_number', 'logo_path', 'currency', 'page_size', 'notifications_enabled'];

    protected function casts(): array
    {
        return ['notifications_enabled' => 'boolean', 'page_size' => 'integer'];
    }
}
