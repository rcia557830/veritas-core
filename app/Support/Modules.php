<?php

namespace App\Support;

use App\Models\Client;
use App\Models\ComplianceRecord;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\Invoice;
use App\Models\KnowledgeArticle;
use App\Models\LedgerEntry;
use App\Models\Notice;

final class Modules
{
    public static function all(): array
    {
        return [
            'clients' => ['model' => Client::class, 'title' => 'Clients', 'singular' => 'client', 'icon' => 'people', 'label' => 'business_name', 'date' => 'created_at', 'search' => ['client_code', 'business_name', 'contact_person', 'email', 'tin'], 'columns' => ['client_code' => 'Client code', 'business_name' => 'Business name', 'business_type' => 'Type', 'status' => 'Status'],
                'fields' => ['business_name' => ['Business name', 'text', true], 'business_type' => ['Business type', ['Sole Proprietorship', 'Partnership', 'Corporation', 'Cooperative', 'Professional', 'Other'], true], 'contact_person' => ['Contact person', 'text'], 'email' => ['Email', 'email'], 'phone' => ['Phone', 'text'], 'tin' => ['TIN', 'text'], 'address' => ['Address', 'textarea'], 'registration_status' => ['Registration', ['Pending', 'On file'], true], 'business_license_status' => ['Business license', ['Pending', 'On file'], true], 'status' => ['Status', ['Active', 'Inactive', 'Archived'], true], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Active', 'Inactive', 'Archived']],
            'documents' => ['model' => Document::class, 'title' => 'Documents', 'singular' => 'document', 'icon' => 'folder2-open', 'label' => 'title', 'date' => 'received_date', 'search' => ['document_number', 'title', 'document_type', 'notes'], 'columns' => ['document_number' => 'Reference', 'title' => 'Document', 'client' => 'Client', 'received_date' => 'Received', 'status' => 'Status'],
                'fields' => ['title' => ['Title', 'text', true], 'document_type' => ['Document type', ['Certificate of Registration', 'Business Permit', 'BIR Document', 'Tax Return', 'Receipt', 'Invoice', 'Bank Statement', 'Financial Statement', 'Government Form', 'Supporting Document', 'Other'], true], 'status' => ['Status', ['Submitted', 'Under Review', 'Needs Clarification', 'Reviewed', 'Approved', 'Rejected'], true], 'received_date' => ['Received date', 'date', true], 'due_date' => ['Follow-up date', 'date'], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Submitted', 'Under Review', 'Needs Clarification', 'Reviewed', 'Approved', 'Rejected']],
            'ledger' => ['model' => LedgerEntry::class, 'title' => 'General Journal', 'singular' => 'transaction', 'icon' => 'journal-text', 'label' => 'description', 'date' => 'transaction_date', 'search' => ['reference_number', 'description', 'notes'], 'columns' => ['transaction_date' => 'Date', 'reference_number' => 'Reference', 'description' => 'Description', 'client' => 'Client', 'status' => 'Status'],
                'fields' => ['transaction_date' => ['Transaction date', 'date', true], 'reference_number' => ['Reference', 'text'], 'description' => ['Description', 'text', true], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Draft', 'For Review', 'Reviewed', 'Needs Correction']],
            'compliance' => ['model' => ComplianceRecord::class, 'title' => 'Compliance', 'singular' => 'requirement', 'icon' => 'calendar2-check', 'label' => 'requirement', 'date' => 'due_date', 'search' => ['agency', 'requirement', 'reporting_period', 'reference_number', 'notes'], 'columns' => ['requirement' => 'Requirement', 'client' => 'Client', 'agency' => 'Agency', 'due_date' => 'Due date', 'status' => 'Status'],
                'fields' => ['agency' => ['Agency', ['BIR', 'SEC', 'DTI', 'CDA', 'LGU', 'BOA', 'DOLE', 'SSS', 'PhilHealth', 'Pag-IBIG', 'Other'], true], 'requirement' => ['Requirement', 'text', true], 'reporting_period' => ['Reporting period', 'text'], 'due_date' => ['Due date', 'date', true], 'status' => ['Status', ['Pending', 'In Preparation', 'Ready for Filing', 'Filed'], true], 'filed_date' => ['Filed date', 'date'], 'reference_number' => ['Filing reference', 'text'], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Pending', 'In Preparation', 'Ready for Filing', 'Filed', 'Overdue']],
            'billing' => ['model' => Invoice::class, 'title' => 'Billing', 'singular' => 'invoice', 'icon' => 'receipt', 'label' => 'invoice_number', 'date' => 'invoice_date', 'search' => ['invoice_number', 'notes'], 'columns' => ['invoice_number' => 'Invoice', 'client' => 'Client', 'due_date' => 'Due date', 'total_amount' => 'Total', 'balance' => 'Balance', 'status' => 'Status'],
                'fields' => ['invoice_date' => ['Invoice date', 'date', true], 'due_date' => ['Due date', 'date', true], 'tax' => ['Tax amount (PHP)', 'number', true], 'notes' => ['Notes', 'textarea']], 'statuses' => ['Draft', 'Open', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled']],
            'knowledge' => ['model' => KnowledgeArticle::class, 'title' => 'Knowledge', 'singular' => 'article', 'icon' => 'book', 'label' => 'title', 'date' => 'created_at', 'search' => ['title', 'category', 'content', 'tags'], 'columns' => ['title' => 'Title', 'category' => 'Category', 'status' => 'Status', 'created_at' => 'Created'],
                'fields' => ['title' => ['Title', 'text', true], 'category' => ['Category', ['Accounting', 'Taxation', 'Client Service', 'BIR', 'SEC', 'Compliance', 'Internal Procedure', 'Billing', 'Documentation', 'Other'], true], 'status' => ['Status', ['Draft', 'Published', 'Archived'], true], 'tags' => ['Tags (comma separated)', 'text'], 'content' => ['Content', 'textarea', true]], 'statuses' => ['Draft', 'Published', 'Archived']],
        ];
    }

    public static function permissionFor(string $model): string
    {
        return [Client::class => 'client', Document::class => 'document', LedgerEntry::class => 'bookkeeping', ComplianceRecord::class => 'compliance', Invoice::class => 'billing', KnowledgeArticle::class => 'knowledge', Notice::class => 'notice', DocumentRequirement::class => 'requirement'][$model];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? abort(404);
    }
}
