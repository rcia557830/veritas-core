<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = ['user_id', 'client_id', 'action', 'module', 'record_id', 'description', 'ip_address', 'event_class', 'entity_type', 'evidence'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit history is append-only.'));
    }

    protected function casts(): array
    {
        return ['evidence' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}
