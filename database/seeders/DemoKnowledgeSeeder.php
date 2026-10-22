<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit;
use App\Support\DemoDatabase;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Add missing training material without resetting existing articles or financial work. */
class DemoKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatabase::check();
        if (! Setting::where('firm_name', 'VERITAS FICTIONAL PHASE 6 DEMO')->exists()) {
            throw new \RuntimeException('Prepare the isolated Phase 6 demo first.');
        }
        $owner = User::where('email', 'owner@demo.example.test')->sole();
        $previous = auth()->user();
        try {
            auth()->setUser($owner);
            DB::transaction(function () use ($owner) {
                foreach (self::articles() as [$title, $category, $purpose, $instructions, $check]) {
                    $slug = 'demo-'.Str::slug($title);
                    // Include archived records: a repeat run must not resurrect or duplicate them.
                    if (KnowledgeArticle::withTrashed()->where('slug', $slug)->orWhereRaw('LOWER(title) = ?', [mb_strtolower($title)])->exists()) {
                        continue;
                    }
                    $article = KnowledgeArticle::create([
                        'slug' => $slug, 'title' => $title, 'category' => $category,
                        'content' => "PURPOSE\n$purpose\n\nWORKING INSTRUCTIONS\n$instructions\n\nCOMPLETION CHECK\n$check\n\nTraining material for the fictional demo. Apply the firm's approved policies to real work; verify official requirements separately.",
                        'tags' => ['demo', 'training'], 'status' => 'Published', 'author_id' => $owner->id,
                    ]);
                    Audit::record('create', 'knowledge', $article, 'Published fictional demo training material.');
                }
            });
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }

    public static function articles(): array
    {
        return [
            ['Understanding the Client Onboarding Process', 'Client Service',
                'Explain how an accepted client becomes ready for accounting work.',
                "1. Record the fictional business identity and assign responsible staff.\n2. Define applicable onboarding requirements and link received documents.\n3. Have authorized staff validate evidence and resolve missing items before activation.",
                'Confirm the assigned person can see the client and the readiness screen supports activation. A file upload alone is not approval.'],
            ['Introduction to Bookkeeping', 'Accounting', 'Explain the relationship between source documents, journals and financial reports.',
                "1. Identify the entity, transaction date and business purpose from source evidence.\n2. Select active accounts and record equal debit and credit totals.\n3. Submit for independent review. Only posted journals affect financial reports; a voucher represents the same journal.",
                'Trace one receipt from its evidence to its journal and posted report without counting the voucher twice.'],
            ['Document Verification Standards', 'Documentation', 'Provide a consistent evidence quality check.',
                "1. Match the file to the assigned client, document type and relevant period.\n2. Check legibility, completeness, amounts and apparent duplicates.\n3. Record the validation decision; request clarification for discrepancies and retain the original source.",
                'The recorded decision explains unresolved issues. Approval must reflect verification, not merely receipt.'],
            ['Accounting Records Management', 'Accounting', 'Keep a traceable chain from source evidence to reports.',
                "1. Use stable references and client-specific accounting periods.\n2. Retain source files and link them to the transaction they support.\n3. Preserve posted records and review history. Escalate corrections through the approved accounting process.",
                'An authorized reviewer can retrieve the journal, evidence, review decision and posting date.'],
            ['Client Confidentiality and Data Protection', 'Client Service', 'Limit disclosure during routine client work.',
                "1. Work only with clients assigned or otherwise authorized to you.\n2. Share the minimum necessary information through firm-approved channels.\n3. Keep credentials private and report unexpected record access to the designated manager.",
                'Remove real personal information from demonstrations and verify recipients before sharing a document.'],
            ['Financial Reporting Overview', 'Accounting', 'Explain what the existing financial reports summarize.',
                "1. Select one client and the intended reporting dates.\n2. Review posted journals in the General Ledger and compare totals to the Trial Balance.\n3. Review income and expense results and the Balance Sheet equation. Draft and review-stage work is excluded.",
                'Debits equal credits and assets equal liabilities plus equity, including current earnings. Investigate differences before distribution.'],
            ['Client Onboarding Procedure', 'Internal Procedure', 'Activate only clients whose applicable onboarding evidence is ready.',
                "1. Create the profile and assign staff.\n2. Configure requirements and link submissions.\n3. Independently validate documents, resolve missing evidence, and use the readiness check before activation.",
                'Retain the activation decision and required approved evidence.'],
            ['Document Intake and Validation Procedure', 'Internal Procedure', 'Move received documents through an accountable validation workflow.',
                "1. Confirm client, document type, received date and source filename.\n2. Upload the readable file and link the matching requirement.\n3. Authorized staff review the document and record Approved, Rejected or Needs Clarification with actionable remarks.",
                'The requirement points to the correct client document and outstanding questions have an assigned follow-up.'],
            ['General Journal Preparation Procedure', 'Internal Procedure', 'Prepare an evidence-backed journal for independent review.',
                "1. Open My Journals and select an assigned client and open period.\n2. Enter the transaction date, reference, purpose and active accounts.\n3. Balance the lines, attach evidence, save the draft and submit. A returned entry requires correction and resubmission.",
                'The creator is the signed-in preparer. The period covers the transaction date and totals reconcile to the source.'],
            ['Journal Review and Posting Procedure', 'Internal Procedure', 'Separate preparation from approval and posting.',
                "1. An authorized reviewer other than the creator checks the submitted journal, classifications and evidence.\n2. Return defects with specific correction notes or mark the journal Reviewed.\n3. An authorized poster posts the reviewed journal after eligibility checks pass.",
                'Verify the posted transaction is read-only and appears once in the ledger and Trial Balance.'],
            ['Accounting Voucher Processing Procedure', 'Internal Procedure', 'Select the proper voucher wrapper for one journal.',
                "1. Choose JV for adjustments, CV for checks, CR for receipts or CD for cash disbursements.\n2. Select client, date, period, accounts and evidence; record payer/payee and cash account where required. CV also requires check details.\n3. Save and route the linked journal through independent review and authorized posting.",
                'Compare the voucher amount to the journal lines. Never create a second journal merely to represent the voucher.'],
            ['Monthly Compliance Monitoring Procedure', 'Internal Procedure', 'Track applicable work and document readiness each month.',
                "1. Confirm each requirement and its official due date from an authoritative source.\n2. Record the reporting period, assignee and internal submission target.\n3. Review pending evidence, open follow-ups and retain completion evidence before closing work.",
                'Demo dates are illustrative. No training date may be represented as a statutory deadline.'],
            ['Billing and Payment Recording Procedure', 'Internal Procedure', 'Record firm service charges and receipts accurately.',
                "1. Create the invoice with service quantities, prices and applicable tax.\n2. Record a receipt against the correct invoice using a distinct reference and actual payment date.\n3. Confirm partial payments reduce the balance and that the receipt does not exceed the amount outstanding.",
                'Invoice total minus receipts equals the balance. Firm billing is separate from client entity books.'],
            ['Missing Document Follow-Up Procedure', 'Internal Procedure', 'Resolve missing evidence with accountable requests.',
                "1. Identify the unmet requirement and assign a responsible staff member.\n2. Record the requested document, follow-up date and specific deficiency.\n3. Receive and validate the replacement, link it to the requirement, then resolve the follow-up.",
                'Do not mark a requirement satisfied on the strength of an unanswered request.'],
            ['Client Onboarding Checklist Template', 'Client Service', 'Copy this checklist into the approved client work record.',
                "Client code: [code]\nAssigned staff: [name]\nBusiness type and engagement scope: [details]\n[ ] Profile verified\n[ ] Applicable requirements configured\n[ ] Received documents linked\n[ ] Independent validation complete\n[ ] Missing items resolved\nActivation reviewer/date: [name/date]",
                'Replace every bracketed field and confirm readiness before activation.'],
            ['Document Request Email Template', 'Documentation', 'Prepare a clear request through approved communication channels.',
                "Subject: Document request - [client code] - [period]\nDear [authorized contact],\nPlease provide [specific documents/pages] for [purpose] through [approved secure channel] by [agreed internal target]. Please confirm the period and ensure all pages are legible. Contact [assigned staff] if clarification is needed.\nRegards, [staff name]",
                'Verify recipient authorization, requested period and internal target. This sample does not send an email.'],
            ['Missing Document Follow-Up Template', 'Documentation', 'Record and communicate a focused follow-up.',
                "Client/requirement: [code/name]\nOriginal request date: [date]\nStill missing or unclear: [specific item]\nReason required: [purpose]\nResponsible staff: [name]\nNext follow-up: [date]\nMessage: Please provide [item] through [approved channel] or advise the expected availability.\nResolution evidence/date: [link/date]",
                'Close only after the received document is linked and its validation outcome is recorded.'],
            ['General Journal Entry Template', 'Accounting', 'Prepare journal details before entering them in My Journals.',
                "Client: [assigned client]\nTransaction date/open period: [date/period]\nReference and purpose: [source/purpose]\nDebit account/amount: [active account/PHP]\nCredit account/amount: [active account/PHP]\nSupporting documents: [references]\nPreparer: [name]\nIndependent reviewer: [different authorized person]\nCorrection notes: [if returned]",
                'Total debits equal total credits; source amount and date agree. Submit using the application workflow.'],
            ['Accounting Voucher Template', 'Accounting', 'Capture the fields required by the selected voucher type.',
                "Type: [JV/CV/CR/CD]\nClient/date/period: [values]\nSystem-issued reference: [after save]\nDescription: [business purpose]\nPayer/payee: [for CR/CV/CD]\nCash account and amount: [for CR/CV/CD]\nCheck number/date: [CV only]\nDebit/credit lines: [accounts and amounts]\nEvidence: [document references]\nLinked journal: [system link]",
                'Reconcile the amount to the linked journal and retain one accounting posting.'],
            ['Monthly Compliance Checklist Template', 'Compliance', 'Organize one month of verified requirements.',
                "Client/reporting period: [values]\nRequirement/agency: [applicable requirement]\nOfficial source and date checked: [reference/date]\nVerified due date: [date]\nInternal target: [date]\nAssignee: [name]\n[ ] Documents requested\n[ ] Evidence validated\n[ ] Work reviewed\n[ ] Completion evidence retained\nOpen issues/next action: [details]",
                'Use verified dates for actual work. Sample dates are never official filing guidance.'],
            ['Client Communication Template', 'Client Service', 'Keep an auditable, minimal record of a client interaction.',
                "Client code: [code]\nAuthorized contact/channel: [details]\nDate and staff member: [values]\nPurpose: [question/update]\nFacts confirmed: [summary]\nAction required and owner: [details]\nAgreed follow-up date: [date]\nRelated record: [reference]\nMessage: We confirm [facts]. Please [action] by [agreed date].",
                'Exclude unnecessary sensitive information and verify that commitments have an owner.'],
            ['Document Validation Checklist Template', 'Documentation', 'Support a consistent review decision.',
                "Client/document/period: [values]\nReviewer/date: [values]\n[ ] Correct client\n[ ] All pages legible\n[ ] Relevant dates checked\n[ ] Amounts agree to source\n[ ] Duplicate check completed\n[ ] Correct requirement linked\nDecision: [Approved/Rejected/Needs Clarification]\nReason and requested correction: [details]",
                'Store the decision with the document; unchecked items need resolution or an explained exception.'],
            ['General Journal Preparation Guidelines', 'Accounting', 'Keep entries clear, balanced and reviewable.',
                "Use the correct client entity and an open period covering the transaction date. Describe the business event and source reference. Select active accounts by substance, attach evidence and submit a balanced draft. Correct returned items using the reviewer's notes.",
                'A different authorized user reviews the entry. Posted records are preserved.'],
            ['Debit and Credit Recording Guidelines', 'Accounting', 'Apply double-entry reasoning to common demo transactions.',
                'A service invoice debits receivables and credits revenue. A client payment debits cash and credits receivables. Equipment bought on credit debits equipment and credits payables. Paying that supplier debits payables and credits cash. Rent paid debits rent expense and credits cash.',
                'Equal totals alone do not prove correctness: verify the entity, classification, amount and period against evidence.'],
            ['Document Submission Guidelines', 'Documentation', 'Provide readable, correctly identified evidence.',
                'Submit complete files using an approved channel. Identify the client code, document type and period. Keep originals available and avoid unrelated personal information. When replacing an unclear file, explain which document is superseded so reviewers can preserve traceability.',
                'Confirm receipt and validation separately; submission is not approval.'],
            ['Client Data Confidentiality Guidelines', 'Client Service', 'Protect client information throughout the workflow.',
                'Access only authorized clients and verify recipients before sharing. Keep credentials private and use approved storage. Technical administration does not automatically grant confidential accounting access. Report unexpected access to the designated manager and avoid further disclosure.',
                'Use fictional information for training; review attachments for sensitive details before sharing.'],
            ['Accounting Voucher Guidelines', 'Accounting', 'Maintain a single traceable accounting event.',
                'Use JV for journal adjustments, CV for checks, CR for receipts and CD for cash disbursements. Keep references unique, record required party and check details, and attach evidence. The voucher inherits the linked journal workflow; review and posting controls still apply.',
                'Reconcile the voucher and journal, and count the posted journal once in financial reports.'],
            ['Compliance Monitoring Guidelines', 'Compliance', 'Separate official obligations from internal work planning.',
                'Verify applicability and due dates for each client from official sources. Record internal submission targets and responsible staff. Monitor pending documents and escalate delays early. Retain proof of completion before closing the record.',
                'Treat every demo deadline as illustrative; do not infer a legal deadline from sample data.'],
            ['Financial Record-Keeping Guidelines', 'Accounting', 'Preserve a reliable accounting trail.',
                'Use consistent source references and client-specific accounts. Retain evidence, review notes and posting history. Reconcile posted balances to source records at period end. Route errors in posted work through the approved correction process instead of rewriting history.',
                'An authorized reviewer can reproduce the report for the selected client and dates.'],
            ['Supporting Document Guidelines', 'Documentation', 'Link sufficient evidence to each transaction.',
                'Attach the relevant invoice, receipt, agreement or statement to the correct client journal. Check dates and amounts and explain allocations where one source supports multiple lines. Resolve unreadable or mismatched evidence before review. Preserve retained files used for approval.',
                'Open the attachment from the detail page and compare it to the journal before marking review complete.'],
        ];
    }
}
