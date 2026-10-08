<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = ['client_id', 'document_number', 'title', 'document_type', 'status', 'received_date', 'due_date', 'file_path', 'original_file_name', 'mime_type', 'notes', 'uploaded_by'];

    protected function casts(): array
    {
        return ['received_date' => 'date', 'due_date' => 'date'];
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
