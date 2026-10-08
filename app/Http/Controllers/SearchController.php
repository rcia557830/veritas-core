<?php

namespace App\Http\Controllers;

use App\Services\Records;
use App\Support\Modules;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate(['q' => 'nullable|string|max:150']);
        $results = [];
        foreach (Modules::all() as $module => $config) {
            foreach (Records::query($module, $request)->limit(6)->get() as $record) {
                $results[] = [
                    'title' => $record->{$config['label']}, 'type' => $config['title'], 'client' => $record->client?->business_name,
                    'status' => $record->display_status ?? $record->status, 'url' => route($module.'.show', $record), 'icon' => $config['icon']];
            }
        }

        return response()->json($results);
    }
}
