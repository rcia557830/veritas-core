<?php

// Included only by guarded prepare.php, after disposable fixtures are created.
use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\DeadlineCalculator;
use App\Services\OnboardingReadiness;
use App\Services\Summary;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Tests\Support\JournalFixtures;

$owner = User::where('email', 'owner@veritascore.local')->firstOrFail();
$bookkeeper = User::where('email', 'bookkeeper@veritascore.local')->firstOrFail();
$manager = User::where('email', 'manager@veritascore.local')->firstOrFail();
$privateClient = Client::create([
    'client_code' => 'SYN-PRIVATE',
    'business_name' => 'SYNTHETIC PRIVATE CLIENT',
    'business_type' => 'Corporation',
    'created_by' => $owner->id,
    'assigned_to' => $owner->id,
    'status' => 'Active',
]);
$assignedClient = Client::where('assigned_to', $bookkeeper->id)->firstOrFail();
$fixtures = new class
{
    use JournalFixtures;

    public function make(User $user, Client $client, string $status, string $reference): LedgerEntry
    {
        $entry = $this->structuredJournal($user, $status, $client);
        $entry->update(['reference_number' => $reference]);

        return $entry;
    }
};
$fixtures->make($bookkeeper, $assignedClient, 'Draft', 'ROLE-OWN-DRAFT');
$fixtures->make($bookkeeper, $assignedClient, 'Needs Correction', 'ROLE-OWN-CORRECTION');
$fixtures->make($manager, $assignedClient, 'For Review', 'ROLE-MANAGER-SELF');
$fixtures->make($owner, $assignedClient, 'Draft', 'ROLE-OTHER-DRAFT');
$privateJournal = $fixtures->make($bookkeeper, $privateClient, 'Draft', 'ROLE-PRIVATE-DRAFT');
$expectations = [];
foreach (['owner' => $owner, 'office-manager' => $manager, 'bookkeeper' => $bookkeeper] as $slug => $user) {
    Auth::setUser($user);
    $clients = Client::when($slug === 'bookkeeper', fn ($q) => $q->where('assigned_to', $user->id));
    $clientIds = (clone $clients)->pluck('id');
    $journals = LedgerEntry::whereIn('client_id', $clientIds);
    $documents = Document::whereIn('client_id', $clientIds);
    $upcoming = ComplianceRecord::whereIn('client_id', $clientIds)->get()->filter(fn ($r) => DeadlineCalculator::isFilingApproaching($r))->count();
    $overdue = ComplianceRecord::whereIn('client_id', $clientIds)->get()->filter(fn ($r) => DeadlineCalculator::isSubmissionOverdue($r))->count();
    $cards = [$slug === 'bookkeeper' ? 'Assigned Active Clients' : 'Active Clients' => (clone $clients)->where('status', 'Active')->count()];
    if ($slug === 'office-manager') {
        $cards += ['Documents Awaiting Validation' => (clone $documents)->whereIn('status', ['Submitted', 'Under Review'])->count(), 'Pending Onboarding' => OnboardingReadiness::organization()['pending'], 'Pending Journal Reviews' => (clone $journals)->where('status', 'For Review')->where('created_by', '!=', $user->id)->count()];
    } elseif ($slug === 'owner') {
        $cards += ['Documents Needing Attention' => (clone $documents)->whereIn('status', ['Submitted', 'Under Review', 'Needs Clarification'])->count(), 'Pending Journal Reviews' => (clone $journals)->where('status', 'For Review')->count()];
    } else {
        $cards += ['Draft Journals' => (clone $journals)->where('created_by', $user->id)->where('status', 'Draft')->count(), 'Journals Needing Correction' => (clone $journals)->where('created_by', $user->id)->where('status', 'Needs Correction')->count(), 'Documents Needing Attention' => (clone $documents)->whereIn('status', ['Submitted', 'Under Review', 'Needs Clarification'])->count()];
    }
    $cards['Upcoming Compliance Deadlines'] = $upcoming;
    if ($slug === 'bookkeeper') {
        $cards['Submitted Journals'] = (clone $journals)->where('created_by', $user->id)->where('status', 'For Review')->count();
    } else {
        $cards['Overdue Requirements'] = $overdue;
    }
    if ($slug === 'owner') {
        $cards['Outstanding Billing'] = Money::format(Summary::billing(Invoice::whereIn('client_id', $clientIds))['outstanding']);
    }
    $expectations[$slug] = array_map('strval', $cards);
}
$expectations['super-admin'] = [
    'Active employees' => (string) User::where('status', 'Active')->count(),
    'Pending invitations' => (string) User::where('status', 'Pending Invitation')->count(),
    'Inactive accounts' => (string) User::where('status', 'Inactive')->count(),
    'Roles and Permissions' => (string) Role::count(),
];
file_put_contents(dirname(getenv('VERITAS_TEST_DATADIR')).'/role-dashboard-expectations.json', json_encode(['cards' => $expectations, 'private_client' => $privateClient->id, 'private_journal' => $privateJournal->id], JSON_THROW_ON_ERROR));
Auth::forgetUser();
