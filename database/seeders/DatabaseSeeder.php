<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Development seed accounts are disabled outside local/testing environments.');
        }
        $this->call(PermissionSeeder::class);
        $adminRole = Role::where('slug', 'owner')->firstOrFail();
        $staffRole = Role::where('slug', 'bookkeeper')->firstOrFail();
        $admin = User::firstOrCreate(['email' => 'owner@veritascore.local'], ['name' => 'System Owner', 'password' => 'password123', 'role_id' => $adminRole->id, 'status' => 'Active']);
        $staff = User::firstOrCreate(['email' => 'bookkeeper@veritascore.local'], ['name' => 'Sample Bookkeeper', 'password' => 'password123', 'role_id' => $staffRole->id, 'status' => 'Active']);
        User::firstOrCreate(['email' => 'manager@veritascore.local'], ['name' => 'Sample Office Manager', 'password' => 'password123', 'role_id' => Role::where('slug', 'office-manager')->firstOrFail()->id, 'status' => 'Active']);
        Setting::firstOrCreate(['id' => 1], ['firm_name' => 'RBCIA Accounting Firm', 'firm_address' => 'Davao City, Philippines', 'firm_email' => 'office@example.com', 'currency' => 'PHP', 'page_size' => 10, 'notifications_enabled' => true]);
        $names = ['Davao Prime Trading', 'Lanang Café Group', 'Calinan Growers Cooperative', 'Southline Professional Services', 'Matina Builders', 'Northpoint Logistics'];
        foreach ($names as $index => $name) {
            $client = Client::firstOrCreate(['client_code' => 'CL-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT)], ['business_name' => $name, 'business_type' => ['Sole Proprietorship', 'Corporation', 'Cooperative', 'Partnership'][$index % 4], 'contact_person' => ['Mara Santos', 'Paolo Reyes', 'Elena Cruz'][$index % 3], 'email' => 'accounts'.($index + 1).'@example.com', 'registration_status' => 'On file', 'business_license_status' => 'On file', 'status' => 'Active', 'created_by' => $admin->id, 'assigned_to' => $staff->id]);
            Document::firstOrCreate(['document_number' => 'DOC-'.($index + 1)], ['client_id' => $client->id, 'title' => 'Monthly supporting records', 'document_type' => 'Receipt', 'status' => ['Submitted', 'Under Review', 'Needs Clarification', 'Reviewed', 'Approved', 'Submitted'][$index], 'received_date' => today()->subDays(3), 'due_date' => today()->addDays(2), 'notes' => 'Confirm the received records with the client.', 'uploaded_by' => $staff->id]);
            ComplianceRecord::firstOrCreate(['client_id' => $client->id, 'requirement' => 'Periodic report preparation'], ['agency' => ['BIR', 'SEC', 'CDA', 'BIR', 'LGU', 'SSS'][$index], 'reporting_period' => today()->format('Y-m'), 'due_date' => today()->addDays([-2, 3, 8, 14, 21, 1][$index]), 'status' => 'Pending', 'assigned_to' => $staff->id, 'created_by' => $admin->id, 'notes' => 'Internal planning date. Verify the applicable deadline before filing.']);
            $ledger = LedgerEntry::firstOrCreate(['reference_number' => 'OR-'.($index + 101)], ['client_id' => $client->id, 'transaction_date' => today()->subDays(2), 'description' => 'Office supplies purchase', 'status' => 'For Review', 'created_by' => $staff->id]);
            if (! $ledger->items()->exists()) {
                $ledger->items()->createMany([['account_name' => 'Office Supplies Expense', 'debit' => '2500.00', 'credit' => '0.00'], ['account_name' => 'Cash', 'debit' => '0.00', 'credit' => '2500.00']]);
            }
            $invoice = Invoice::firstOrCreate(['invoice_number' => 'INV-'.today()->year.'-'.($index + 1)], ['client_id' => $client->id, 'invoice_date' => today()->subDays(15), 'due_date' => today()->addDays($index === 0 ? -3 : 10), 'tax' => '0.00', 'status' => 'Open', 'created_by' => $admin->id]);
            if (! $invoice->items()->exists()) {
                $invoice->items()->create(['description' => 'Monthly accounting services', 'quantity' => '1.00', 'unit_price' => '6500.00']);
            }
            if ($index === 2 && ! $invoice->payments()->exists()) {
                $invoice->payments()->create(['payment_date' => today(), 'amount' => '6500.00', 'payment_method' => 'Bank Transfer', 'reference_number' => 'PAY-001', 'recorded_by' => $staff->id]);
            }
        }
        KnowledgeArticle::firstOrCreate(['slug' => 'ten-day-preparation'], ['title' => 'Start preparation 10 days ahead', 'category' => 'Compliance', 'content' => 'Request supporting information at least 10 days before the confirmed deadline. Assign a reviewer, resolve missing information, and retain the filing acknowledgment. Always verify the deadline applicable to the client.', 'tags' => ['preparation', 'filing'], 'status' => 'Published', 'author_id' => $admin->id]);
        KnowledgeArticle::firstOrCreate(['slug' => 'document-intake'], ['title' => 'From intake to approved document', 'category' => 'Documentation', 'content' => 'Log each document against its client. Move it to Under Review when work begins. Use Needs Clarification for missing or unreadable material. Mark it Reviewed after checking and Approved when ready for the client file.', 'tags' => ['documents', 'quality'], 'status' => 'Published', 'author_id' => $admin->id]);
    }
}
