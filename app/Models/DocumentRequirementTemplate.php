<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentRequirementTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'type', 'description', 'is_required', 'default_due_days'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'default_due_days' => 'integer'];
    }

    public function requirements()
    {
        return $this->hasMany(DocumentRequirement::class, 'template_id');
    }
}
