<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function edit()
    {
        Gate::authorize('workspace.manage');

        return view('settings.edit', ['setting' => Setting::firstOrFail()]);
    }

    public function update(Request $request)
    {
        Gate::authorize('workspace.manage');
        $data = $request->validate(['firm_name' => 'required|string|max:255', 'firm_address' => 'nullable|string|max:2000', 'firm_email' => 'nullable|email|max:255', 'contact_number' => 'nullable|string|max:60', 'currency' => 'required|in:PHP', 'page_size' => 'required|in:10,25,50', 'notifications_enabled' => 'nullable|boolean', 'logo' => 'nullable|image|mimes:png,jpg,jpeg|max:2048']);
        $setting = Setting::firstOrFail();
        $data['notifications_enabled'] = $request->boolean('notifications_enabled');
        unset($data['logo']);
        $newPath = null;
        $old = $setting->logo_path;
        try {
            if ($request->hasFile('logo')) {
                $newPath = $request->file('logo')->store('logos', 'public');
                $data['logo_path'] = $newPath;
            }$setting->update($data);
        } catch (\Throwable $e) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }throw $e;
        }
        if ($newPath && $old) {
            Storage::disk('public')->delete($old);
        }
        Audit::record('settings', 'workspace', $setting, 'Firm settings updated.');

        return back()->with('success', 'Workspace settings saved.');
    }
}
