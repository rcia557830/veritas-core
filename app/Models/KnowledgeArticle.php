<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class KnowledgeArticle extends Model
{
    use SoftDeletes;

    public const CONTENT_TYPES = [
        'article' => 'Article',
        'procedure' => 'Procedure',
        'template' => 'Template',
        'guideline' => 'Guideline',
    ];

    protected $fillable = ['content_type', 'title', 'slug', 'category', 'content', 'tags', 'status', 'author_id'];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function getContentTypeLabelAttribute(): string
    {
        return self::CONTENT_TYPES[$this->content_type] ?? ucfirst($this->content_type ?? 'article');
    }

    public function getPlainExcerptAttribute(): string
    {
        $text = $this->content ?? '';
        // Strip markdown headings (# Heading)
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);
        // Strip markdown bold / italic (**bold**, *italic*, __bold__, _italic_)
        $text = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $text);
        $text = preg_replace('/(\*|_)(.*?)\1/', '$2', $text);
        // Strip inline code (`code`)
        $text = preg_replace('/`([^`]+)`/', '$1', $text);
        // Strip links ([label](url) -> label)
        $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);
        // Strip blockquotes (> Quote)
        $text = preg_replace('/^\s*>\s*/m', '', $text);
        // Clean excessive whitespace and newlines
        $text = preg_replace('/\s+/', ' ', $text);

        return Str::limit(trim($text), 180);
    }
}
