<?php

namespace App\Services;

use App\Models\Client;
use App\Models\KnowledgeArticle;
use App\Models\Notice;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function query(string $model, ?User $user = null): Builder
    {
        $user ??= auth()->user();
        $query = $model::query();
        $prefix = Modules::permissionFor($model);
        if (! $user?->hasPermission($prefix.'.view')) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->hasAnyRole(['owner', 'office-manager'])) {
            return $query;
        }
        if (! $user->hasRole('bookkeeper')) {
            return $query->whereRaw('1 = 0');
        }
        if ($model === KnowledgeArticle::class) {
            return $query->where(fn ($q) => $q->where('status', 'Published')->orWhere(fn ($own) => $own->where('author_id', $user->id)->where('status', 'Draft')));
        }
        if ($model === Notice::class) {
            return $query->whereRaw('1 = 0');
        }
        if ($model === Client::class) {
            return $query->where('assigned_to', $user->id);
        }

        return $query->whereHas('client', fn ($q) => $q->where('assigned_to', $user->id));
    }

    public static function client(int $id): Client
    {
        return static::query(Client::class)->findOrFail($id);
    }
}
