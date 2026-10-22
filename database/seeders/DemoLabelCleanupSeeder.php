<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\DemoDatabase;
use App\Support\DemoLabels;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoLabelCleanupSeeder extends Seeder
{
    public const FIELDS = [
        'clients' => ['business_name', 'contact_person'],
        'users' => ['name'],
        'documents' => ['title'],
        'document_requirements' => ['name'],
        'knowledge_articles' => ['title'],
        'notices' => ['title'],
        'accounting_years' => ['label'],
        'accounting_periods' => ['label'],
    ];

    public function run(): void
    {
        DemoDatabase::check();
        if (! Setting::where('firm_name', 'VERITAS FICTIONAL PHASE 6 DEMO')->exists()) {
            throw new \RuntimeException('Prepare the isolated Phase 6 demo first.');
        }
        DB::transaction(function () {
            foreach (self::FIELDS as $table => $fields) {
                foreach (DB::table($table)->orderBy('id')->get(['id', ...$fields]) as $row) {
                    $changes = [];
                    foreach ($fields as $field) {
                        $clean = DemoLabels::clean($row->$field);
                        if ($clean !== $row->$field) {
                            $changes[$field] = $clean;
                        }
                    }
                    if ($changes) {
                        // No lifecycle transitions, timestamps, credentials or historical audit rewrites.
                        DB::table($table)->where('id', $row->id)->update($changes);
                    }
                }
            }
            // Keep the clearly synthetic address; remove only the redundant labels.
            DB::table('clients')->where('address', 'like', 'Unit %, Fictional Demo Street, Davao City, Philippines')
                ->update(['address' => DB::raw("REPLACE(address, 'Fictional Demo Street', 'Training Workspace Street')")]);
        });
    }
}
