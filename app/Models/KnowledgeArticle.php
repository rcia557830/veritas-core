<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeArticle extends Model
{
    use SoftDeletes;

    protected $fillable = ['title', 'slug', 'category', 'content', 'tags', 'status', 'author_id'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
