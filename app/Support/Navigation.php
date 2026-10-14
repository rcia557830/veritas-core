<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class Navigation
{
    /** One permission-filtered source for the sidebar and workflow tabs. */
    public static function groups(): array
    {
        $business = auth()->user()->hasAnyRole(['owner', 'office-manager', 'bookkeeper']);
        $admin = auth()->user()->hasRole('super-admin');
        $can = fn (string $model) => $business && Gate::allows('viewAny', $model);
        $link = fn ($label, $route, $allowed, $query = [], $patterns = null) => compact('label', 'route', 'allowed', 'query', 'patterns');
        $groups = [
            'dashboard' => ['label' => 'Dashboard', 'icon' => 'grid-1x2', 'links' => [
                $link('Overview', 'dashboard', true),
                $link('Important notifications', 'notices.index', $business && Gate::allows('notice.view'), [], 'notices.*'),
            ]],
            'clients' => ['label' => 'Clients', 'icon' => 'people', 'links' => [
                $link('Client Directory', 'clients.index', $can(Client::class), [], 'clients.*'),
                $link('Client Onboarding', 'clients.onboarding', $can(Client::class), [], 'clients.onboarding*'),
            ]],
            'documents' => ['label' => 'Documents', 'icon' => 'folder2-open', 'links' => [
                $link('All Documents', 'documents.index', $can(Document::class), [], 'documents.*'),
                $link('Requirements', 'requirements.index', $can(DocumentRequirement::class), [], 'requirements.*'),
                $link('Missing Documents', 'requirements.monitoring', $can(DocumentRequirement::class), ['state' => 'missing']),
                $link('Validation', 'documents.index', $can(Document::class) && Gate::allows('document.validate'), ['queue' => 'validation']),
                $link('Follow-Ups', 'requirements.monitoring', $can(DocumentRequirement::class), ['follow_up' => 'open']),
            ]],
            'accounting' => ['label' => 'Accounting', 'icon' => 'journal-text', 'links' => [
                $link('General Journal', 'ledger.index', $can(LedgerEntry::class), [], 'ledger.*'),
                $link('My Journals', 'ledger.index', $can(LedgerEntry::class) && Gate::allows('create', LedgerEntry::class), ['work' => 'mine']),
                $link('Journal Review Queue', 'ledger.index', $can(LedgerEntry::class) && Gate::allows('bookkeeping.review'), ['work' => 'reviewable']),
                $link('Accounting Vouchers', 'vouchers.index', $can(LedgerEntry::class), [], 'vouchers.*'),
                $link('General Ledger', 'general-ledger.index', $business && Gate::allows('generalLedger', LedgerEntry::class), [], 'general-ledger.*'),
                $link('Chart of Accounts', 'accounts.index', $can(Account::class), [], 'accounts.*'),
                $link('Billing and Payments', 'billing.index', $can(Invoice::class), [], 'billing.*'),
                $link('Financial Audit Trail', 'financial-audit.index', $business && Gate::allows('generalLedger', LedgerEntry::class), [], ['financial-audit.*', 'admin.audit.*']),
                $link('Accounting Settings', 'accounting-periods.index', $can(Account::class), [], ['accounting-periods.*', 'account-templates.*']),
                $link('Record Reports', 'reports.index', $business && Gate::allows('report.view') && Gate::allows('report.generate'), [], 'reports.*'),
            ]],
            'compliance' => ['label' => 'Compliance', 'icon' => 'calendar2-check', 'links' => [
                $link('Requirements', 'compliance.index', $can(ComplianceRecord::class), [], 'compliance.*'),
                $link('Deadline Monitoring', 'compliance.monitoring', $can(ComplianceRecord::class)),
                $link('Follow-Ups', 'compliance.monitoring', $can(ComplianceRecord::class), ['follow_up' => 'open']),
            ]],
            'knowledge' => ['label' => 'Knowledge Base', 'icon' => 'book', 'links' => [
                $link('Articles', 'knowledge.index', $can(KnowledgeArticle::class), [], 'knowledge.*'),
                $link('Procedures', 'knowledge.index', $can(KnowledgeArticle::class), ['type' => 'Internal Procedure']),
                $link('Templates', 'knowledge.index', $can(KnowledgeArticle::class), ['q' => 'template']),
                $link('Guidelines', 'knowledge.index', $can(KnowledgeArticle::class), ['q' => 'guideline']),
            ]],
            'administration' => ['label' => 'Administration', 'icon' => 'shield-lock', 'links' => [
                $link('User Management', 'admin.users.index', $admin && Gate::allows('viewAny', User::class), [], 'admin.users.*'),
                $link('Roles and Permissions', 'admin.roles.index', $admin && Gate::allows('viewAny', User::class)),
                $link('System Settings', 'workspace.edit', $admin && Gate::allows('workspace.manage'), [], 'workspace.*'),
                $link('System Activity Logs', 'admin.activity.index', $admin && Gate::allows('security.view')),
                $link('Security Logs', 'admin.security.index', $admin && Gate::allows('security.view')),
            ]],
        ];
        foreach ($groups as &$group) {
            $group['links'] = array_values(array_filter($group['links'], fn ($link) => $link['allowed']));
            $best = null;
            $score = -1;
            foreach ($group['links'] as $i => &$item) {
                $item['url'] = route($item['route'], $item['query']);
                $item['active'] = false;
                $matches = request()->routeIs(...(array) ($item['patterns'] ?? $item['route']));
                foreach ($item['query'] as $name => $value) {
                    $matches = $matches && request()->query($name) === $value;
                }
                $rank = count($item['query']) * 100 + strlen(is_array($item['patterns']) ? $item['patterns'][0] : ($item['patterns'] ?? $item['route']));
                if ($matches && $rank > $score) {
                    $best = $i;
                    $score = $rank;
                }
            }
            unset($item);
            $group['active'] = $best !== null;
            if ($best !== null) {
                $group['links'][$best]['active'] = true;
            }
        }
        unset($group);

        return array_filter($groups, fn ($group) => count($group['links']) > 0);
    }
}
