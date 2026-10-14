<?php

namespace Database\Seeders;

use App\Http\Controllers\PaymentController;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountingYear;
use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\JournalPosting;
use App\Services\Accounting\JournalWriter;
use App\Services\Accounting\VoucherWriter;
use App\Services\Audit;
use App\Services\DeadlineCalculator;
use App\Services\OnboardingReadiness;
use App\Support\DemoDatabase;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** An atomic, opt-in dataset. Re-running preserves all demonstration edits. */
class DemoDataSeeder extends Seeder
{
    public const ROLES = ['super-admin', 'owner', 'office-manager', 'bookkeeper'];

    public function run(): void
    {
        DemoDatabase::check();
        if (Setting::where('firm_name', 'VERITAS FICTIONAL PHASE 6 DEMO')->exists()) {
            $this->call(DemoKnowledgeSeeder::class);

            return;
        }
        if (Client::withTrashed()->exists() || User::exists() || Setting::exists()) {
            throw new \RuntimeException('Use an empty disposable demo database. Existing data will not be replaced.');
        }
        $previous = auth()->user();
        try {
            DB::transaction(function () {
                $this->call(PermissionSeeder::class);
                $users = [];
                foreach (self::ROLES as $role) {
                    $password = config('demo.passwords.'.$role) ?? Str::password(32);
                    if (mb_strlen($password) < 12 || strlen($password) > 72) {
                        throw new \RuntimeException('Demo passwords must contain at least 12 characters and at most 72 bytes.');
                    }
                    $users[$role] = User::create(['email' => $role.'@demo.example.test', 'name' => 'Demo '.Str::headline($role),
                        'password' => $password, 'role_id' => Role::where('slug', $role)->sole()->id, 'status' => 'Active']);
                }
                $owner = $users['owner'];
                $manager = $users['office-manager'];
                $bookkeeper = $users['bookkeeper'];
                $names = ['Amihan Paper Trading', 'Hiraya Coffee House', 'Luntian Harvest', 'Sinag Design Studio', 'Tala Engineering',
                    'Habagat Hardware', 'Dalisay Food Works', 'Bughaw Transport', 'Sampaguita Consulting', 'Mayumi Dental Studio',
                    'Balangay Home Goods', 'Silangan Printing', 'Kawayan Growers', 'Alon Creative Partners', 'Liwayway Architecture',
                    'Marilag Textiles', 'Dapithapon Bakery', 'Masagana Producers', 'Lakbay Advisory', 'Mutya Wellness Practice',
                    'Pagsibol Office Supply', 'Malaya Software', 'Buklod Artisans', 'Salinlahi Research', 'Gintong Veterinary Care',
                    'Siklab Kitchen Goods', 'Tahanan Furniture', 'Ani Community Market', 'Ugnayan Logistics', 'Likas Surveying'];
                foreach ($names as $index => $name) {
                    $number = $index + 1;
                    $type = ['Sole Proprietorship', 'Corporation', 'Cooperative', 'Partnership', 'Professional'][$index % 5];
                    $preparer = $number % 6 === 0 ? $manager : $bookkeeper;
                    $client = Client::create(['client_code' => sprintf('DEMO-%03d', $number), 'business_name' => $name.' (Fictional)',
                        'business_type' => $type, 'contact_person' => 'Fictional Contact '.sprintf('%02d', $number),
                        'email' => 'client'.$number.'@example.test', 'phone' => '+63 900 000 '.sprintf('%04d', $number), 'tin' => sprintf('000-000-%03d', $number),
                        'address' => 'Unit '.$number.', Fictional Demo Street, Davao City, Philippines',
                        'registration_status' => $index % 5 === 0 ? 'On file' : 'Pending', 'business_license_status' => $index % 5 === 0 ? 'On file' : 'Pending',
                        'status' => 'Active', 'created_by' => $owner->id, 'assigned_to' => $preparer->id,
                        'notes' => 'Entirely fictional demonstration business. No real registration or contact details.']);
                    auth()->setUser($manager);
                    $evidence = $this->documents($client, $index, $preparer, $manager);
                    $this->accounting($client, $preparer->is($manager) ? $owner : $preparer, $manager, $evidence);
                    auth()->setUser($owner);
                    $this->billing($client, $index, $owner);
                    $this->compliance($client, $index, $owner, $preparer);
                }
                auth()->setUser($owner);
                $this->knowledge($owner);
                // Written last, in the same transaction: repeat runs never rewind live demo work.
                Setting::create(['firm_name' => 'VERITAS FICTIONAL PHASE 6 DEMO', 'firm_address' => 'Fictional Demo Street, Davao City',
                    'firm_email' => 'office@example.test', 'currency' => 'PHP', 'page_size' => 10, 'notifications_enabled' => true]);
            });
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
        $this->call(DemoKnowledgeSeeder::class);
    }

    private function documents(Client $client, int $index, User $preparer, User $manager): Document
    {
        $types = ['Certificate of Registration', 'BIR Document', 'Business Permit', 'Receipt', 'Invoice', 'Bank Statement', 'Supporting Document'];
        $evidence = null;
        foreach ($types as $offset => $type) {
            $requirement = DocumentRequirement::create(['client_id' => $client->id, 'name' => 'Fictional '.$type, 'type' => $type,
                'scope' => $offset < 3 ? 'onboarding' : 'periodic', 'is_required' => true, 'is_active' => true,
                'due_date' => today()->addDays(5), 'created_by' => $manager->id]);
            // Missing is a computed requirement state, never an invented document status.
            if ($index % 5 === 4 && $offset === 2) {
                $requirement->followUps()->create(['status' => 'Open', 'assigned_to' => $preparer->id, 'follow_up_date' => today(),
                    'remarks' => 'Illustrative request for missing permit; no external message sent.', 'created_by' => $manager->id]);

                continue;
            }
            $status = $offset >= 3 ? 'Approved' : ['Approved', 'Submitted', 'Under Review', 'Needs Clarification', 'Approved'][$index % 5];
            $path = 'phase6-demo/'.$client->client_code.'-'.$offset.'.pdf';
            if (! Storage::disk('local')->exists($path)) {
                $html = '<h1>FICTIONAL SAMPLE - NOT VALID FOR FILING</h1><h2>'.e($type).'</h2><p>'.e($client->business_name).'</p><p>'.e($client->client_code).'</p><p>Training attachment only. No real client, tax identifier, bank account or government certificate.</p>';
                Storage::disk('local')->put($path, Pdf::loadHTML($html)->output());
            }
            $document = Document::create(['client_id' => $client->id, 'document_number' => $client->client_code.'-DOC-'.$offset,
                'title' => 'Fictional '.$type, 'document_type' => $type, 'status' => $status, 'received_date' => today(),
                'due_date' => today()->addDays(5), 'uploaded_by' => $preparer->id, 'file_path' => $path,
                'original_file_name' => basename($path), 'mime_type' => 'application/pdf', 'file_sha256' => hash('sha256', Storage::disk('local')->get($path))]);
            $requirement->documents()->attach($document, ['linked_by' => $manager->id]);
            Audit::record('document.validated', 'documents', $document, 'Fictional seed decision: '.$status.'.');
            if ($offset === 6) {
                $evidence = $document;
            }
        }
        if ($index % 10 === 0 && OnboardingReadiness::canActivate($client)['ok']) {
            $client->update(['onboarded_at' => now(), 'onboarded_by' => $manager->id]);
            Audit::record('client.onboarded', 'clients', $client, 'Fictional client activated after all onboarding requirements were verified.');
        }

        return $evidence;
    }

    private function accounting(Client $client, User $preparer, User $reviewer, Document $evidence): void
    {
        $year = AccountingYear::create(['client_id' => $client->id, 'label' => 'Demo '.today()->year, 'starts_on' => today()->startOfYear()->toDateString(), 'ends_on' => today()->endOfYear()->toDateString()]);
        $period = AccountingPeriod::create(['client_id' => $client->id, 'accounting_year_id' => $year->id, 'label' => 'Demo current year', 'starts_on' => $year->starts_on, 'ends_on' => $year->ends_on]);
        $accounts = [];
        foreach ([['100', 'Cash', 'Asset'], ['110', 'Accounts Receivable', 'Asset'], ['120', 'Office Supplies', 'Asset'], ['150', 'Equipment', 'Asset'],
            ['200', 'Accounts Payable', 'Liability'], ['300', 'Capital', 'Equity'], ['400', 'Service Revenue', 'Revenue'], ['500', 'Rent Expense', 'Expense'], ['510', 'Utilities Expense', 'Expense']] as [$code, $name, $classification]) {
            $accounts[$code] = Account::create(['client_id' => $client->id, 'code' => $code, 'name' => $name, 'classification' => $classification, 'is_active' => true])->id;
        }
        $transactions = [
            ['JV', 'Capital contribution', '100', '300', '100000.00', 'Posted'],
            ['JV', 'Service invoice on account', '110', '400', '25000.00', 'Posted'],
            ['CR', 'Client receipt against receivable', '100', '110', '15000.00', 'Posted'],
            ['JV', 'Equipment purchase on account', '150', '200', '30000.00', 'Posted'],
            ['CV', 'Supplier payment against payable', '200', '100', '10000.00', 'Posted'],
            ['CD', 'Office rent payment', '500', '100', '8000.00', 'Posted'],
            ['CD', 'Utility payment', '510', '100', '2000.00', 'Posted'],
            ['JV', 'Office supplies purchase on account', '120', '200', '3000.00', 'Posted'],
            ['JV', 'Draft service adjustment', '110', '400', '1000.00', 'Draft'],
            ['CD', 'Utility payment awaiting review', '510', '100', '500.00', 'For Review'],
            ['CR', 'Client receipt ready to post', '100', '110', '1000.00', 'Reviewed'],
            ['JV', 'Supplies clarification example', '120', '200', '250.00', 'Needs Correction'],
        ];
        foreach ($transactions as [$type, $description, $debit, $credit, $amount, $status]) {
            auth()->setUser($preparer);
            $data = ['client_id' => $client->id, 'type' => $type, 'creation_token' => (string) Str::uuid(),
                'transaction_date' => today()->toDateString(), 'accounting_period_id' => $period->id,
                'description' => 'Fictional: '.$description, 'document_ids' => [$evidence->id], 'items' => [
                    ['account_id' => $accounts[$debit], 'debit' => $amount, 'credit' => '0.00'],
                    ['account_id' => $accounts[$credit], 'debit' => '0.00', 'credit' => $amount]]];
            if ($type !== 'JV') {
                $data += ['party' => 'Fictional '.($type === 'CR' ? 'client payer' : 'supplier payee'), 'cash_account_id' => $accounts['100'], 'amount' => $amount];
            }
            if ($type === 'CV') {
                $data += ['check_number' => $client->client_code.'-CHECK-001', 'check_date' => today()->toDateString()];
            }
            $entry = VoucherWriter::save($data)->journal;
            if ($status === 'Draft') {
                continue;
            }
            $entry = JournalWriter::transition($entry, 'submit', null);
            if ($status === 'For Review') {
                continue;
            }
            auth()->setUser($reviewer);
            if ($status === 'Needs Correction') {
                JournalWriter::transition($entry, 'return', 'Confirm the fictional supplier invoice quantity.');

                continue;
            }
            $entry = JournalWriter::transition($entry, 'review', null);
            if ($status === 'Posted') {
                JournalPosting::post($entry);
            }
        }
    }

    private function billing(Client $client, int $index, User $owner): void
    {
        $invoice = Invoice::create(['client_id' => $client->id, 'invoice_number' => $client->client_code.'-BILL-001',
            'invoice_date' => today()->subDays(15), 'due_date' => today()->addDays($index % 4 === 3 ? -3 : 10),
            'tax' => '0.00', 'status' => 'Open', 'created_by' => $owner->id, 'notes' => 'Fictional firm service bill. Separate from the client entity accounting books.']);
        $invoice->items()->createMany([['description' => 'Monthly bookkeeping service', 'quantity' => '1.00', 'unit_price' => '5000.00'],
            ['description' => 'Document preparation sessions', 'quantity' => '2.00', 'unit_price' => '750.00']]);
        $amounts = match ($index % 4) {
            1 => ['2000.00'], 2 => ['2000.00', '4500.00'], default => []
        };
        foreach ($amounts as $offset => $amount) {
            $request = Request::create('/', 'POST', ['payment_date' => today()->toDateString(), 'amount' => $amount,
                'payment_method' => 'Bank Transfer', 'reference_number' => $client->client_code.'-PAY-'.$offset, 'submission_token' => (string) Str::uuid()]);
            app(PaymentController::class)->store($request, $invoice);
        }
    }

    private function compliance(Client $client, int $index, User $owner, User $preparer): void
    {
        $registry = match ($client->business_type) {
            'Corporation', 'Partnership' => 'SEC', 'Cooperative' => 'CDA', 'Professional' => 'PRC', default => 'DTI'
        };
        foreach (['BIR', $registry, 'LGU', 'Other'] as $offset => $agency) {
            $stage = ($index + $offset) % 5;
            $due = today()->addDays([20, -3, 7, 12, -1][$stage]);
            $record = ComplianceRecord::create(['client_id' => $client->id, 'agency' => $agency,
                'requirement' => 'ILLUSTRATIVE '.($agency === 'Other' ? 'internal document checklist' : $agency.' compliance review'),
                'reporting_period' => today()->format('Y-m'), 'due_date' => $due,
                'submission_deadline' => DeadlineCalculator::submissionDeadline($due)->toDateString(),
                'submission_deadline_is_manual' => false, 'status' => ['Pending', 'Pending', 'Awaiting Client Documents', 'In Preparation', 'Completed'][$stage],
                'assigned_to' => $preparer->id, 'created_by' => $owner->id,
                'notes' => 'ILLUSTRATIVE TRAINING DATE ONLY, not an actual government deadline. Verify applicability and current official deadlines before real use.'.($stage === 3 ? ' Internal review underway.' : '')]);
            if ($stage === 2) {
                $record->followUps()->create(['status' => 'Open', 'assigned_to' => $preparer->id, 'follow_up_date' => today(),
                    'remarks' => 'Fictional client document reminder; no message sent.', 'created_by' => $owner->id]);
            }
        }
    }

    private function knowledge(User $owner): void
    {
        foreach ([
            'Client onboarding procedure' => 'Create the client profile and assign responsible staff. Configure registration and permit requirements. Link submitted files, independently validate them, and check onboarding readiness before activation.',
            'Document intake checklist' => 'Confirm the client, document type and received date. Upload a readable attachment and link it to the matching requirement. Check for duplicates and retain source evidence. Use Under Review while checking and Approved only after verification.',
            'Missing-document follow-up procedure' => 'Open Missing Documents, select the requirement and assign a follow-up date. Record the missing information without unnecessary personal data. Resolve the follow-up after the replacement is received and validated.',
            'Journal entry preparation guide' => 'Choose the correct client and open accounting period. Use active accounts with correct classifications. Enter equal debit and credit totals, attach supporting evidence and submit for independent review. A voucher wraps one journal and must not be counted a second time.',
            'Independent accounting review' => 'The preparer cannot review their own journal. Check accounts, amounts, period and retained evidence. Return errors with a clear correction note. Review then post through the authorized workflow; only posted entries appear in the ledger and reports.',
            'Compliance monitoring workflow' => 'Verify the applicable official deadline for each client. Request documents ten days before filing. Assign follow-ups and review evidence before completing work. All deadlines in this demo are illustrative, not government guidance.',
            'Confidentiality policy' => 'Use only assigned client records. Keep passwords private and use invitations to provision staff. Do not share client attachments outside authorized channels. Report unexpected access and never put real client information in a demonstration.',
            'Billing and payment procedures' => 'Create an invoice with item quantities, unit prices and applicable tax. Record each receipt with a distinct reference. Check the remaining balance after partial payment. Do not accept overpayments or duplicate submissions. Firm billing is distinct from client accounting books.',
        ] as $title => $content) {
            KnowledgeArticle::create(['slug' => 'demo-'.Str::slug($title), 'title' => $title, 'category' => 'Internal Procedure',
                'content' => $content, 'tags' => ['demo', 'procedure'], 'status' => 'Published', 'author_id' => $owner->id]);
        }
    }
}
