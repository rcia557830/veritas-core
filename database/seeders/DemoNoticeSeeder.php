<?php

namespace Database\Seeders;

use App\Http\Controllers\NoticeController;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Notice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Audit;
use App\Support\DemoDatabase;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DemoNoticeSeeder extends Seeder
{
    public function run(): void
    {
        DemoDatabase::check();
        if (! Setting::where('firm_name', 'VERITAS FICTIONAL PHASE 6 DEMO')->exists()) {
            throw new \RuntimeException('Prepare the isolated Phase 6 demo first.');
        }
        $previous = auth()->user();
        try {
            DB::transaction(function () {
                $controller = app(NoticeController::class);
                foreach (self::notices() as $index => [$title, $status, $code, $body]) {
                    $marker = 'demo-notice-v1:'.($index + 1);
                    // Append-only metadata survives title edits, archiving and soft deletion.
                    if (AuditLog::where('action', 'demo.notice.seeded')->where('description', $marker)->exists()
                        || Notice::withTrashed()->whereRaw('LOWER(title) = ?', [mb_strtolower($title)])->exists()) {
                        continue;
                    }
                    $creator = User::where('email', ($index % 3 === 0 ? 'owner' : 'office-manager').'@demo.example.test')->sole();
                    auth()->setUser($creator);
                    $client = $code ? Client::where('client_code', $code)->whereNotNull('assigned_to')->sole() : null;
                    // Use the same authorization, validation, publication and audit path as the UI.
                    $controller->store(Request::create('/notices', 'POST', [
                        'title' => $title, 'body' => $body, 'client_id' => $client?->id,
                    ]));
                    $notice = Notice::where('title', $title)->sole();
                    if ($status !== 'Draft') {
                        $controller->publish($notice);
                    }
                    if ($status === 'Archived') {
                        $controller->archive($notice);
                    }
                    Audit::record('demo.notice.seeded', 'notices', $notice->fresh(), $marker);
                }
            });
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }

    public static function notices(): array
    {
        return [
            ['Monthly Staff Coordination Meeting', 'Published', null,
                "Owners and Office Managers will coordinate the monthly work review. Before the meeting, update assigned client work, unresolved document requests and journals awaiting review.\n\nBring a concise list of blockers, the responsible staff member and a proposed next action. The Office Manager will confirm the meeting time internally and record agreed actions in the relevant workspace records."],
            ['Office Document Handling Reminders', 'Published', null,
                "Keep client records in the approved client workspace and identify each upload by document type and reporting period. Check that all pages are readable before submitting a file for verification.\n\nRetain original evidence and explain replacement files in the record remarks. Report misplaced or incorrectly linked documents to the Office Manager before continuing the review."],
            ['Updated Client Communication Procedures', 'Published', null,
                "Before preparing a client update, confirm the authorized contact, engagement scope and outstanding action with the assigned staff member. Use the approved communication template and include only information necessary for the request.\n\nRecord the agreed follow-up date and responsible person in the client workspace. The Office Manager must review sensitive or unresolved matters before any external communication is arranged."],
            ['Internal System Maintenance Advisory', 'Archived', null,
                "The previous internal maintenance coordination window is closed. Staff were asked to save drafts, finish active uploads and avoid starting review actions during the announced window.\n\nThis advisory is retained for reference. Report any remaining access or document retrieval issue to the Office Manager with the affected record reference; do not include passwords or confidential attachments in the incident summary."],
            ['Monthly Bookkeeping Review Schedule', 'Published', 'DEMO-001',
                "The assigned preparer should organize the current monthly records for the next bookkeeping review. Confirm transaction dates, source references and supporting documents before submitting journals.\n\nThe Office Manager will coordinate an independent reviewer and record any missing evidence or classification questions. Keep unresolved items in the review queue until corrections are supported and resubmitted."],
            ['Journal Submission and Review Reminders', 'Published', null,
                "Submit journals only after confirming the client, open accounting period, active accounts and balanced debit and credit totals. Explain the transaction purpose and attach the supporting evidence.\n\nPreparation and review must remain independent. Address returned comments before resubmission, and use the authorized posting workflow only after review is complete. Posted entries must retain their original audit history."],
            ['Accounting Voucher Preparation Guidelines', 'Published', null,
                "Select the voucher type that matches the transaction: journal adjustment, check, cash receipt or cash disbursement. Record the payer or payee and required check details where applicable.\n\nReconcile the voucher amount to its linked journal and supporting documents. A voucher represents the same accounting event as its journal; do not prepare a second entry solely for the voucher. Route the linked journal through independent review."],
            ['Month-End Reconciliation Reminder', 'Published', null,
                "Before requesting month-end review, reconcile bank and cash balances, receivables and payables to the available statements and schedules. Identify timing differences separately from errors requiring correction.\n\nRetain the reconciliation evidence with the relevant client records and assign each unresolved item to a responsible staff member. The reviewer should confirm that posted journals support the reporting period before reports are released."],
            ['Client Onboarding Requirements Update', 'Draft', 'DEMO-005',
                "Proposed onboarding review: the assigned staff member should check the applicable registration and business permit requirements and confirm which evidence remains outstanding. Link received files to the matching requirement before requesting validation.\n\nThe Office Manager must confirm the request list and internal target before this notice is published. Client activation remains subject to the existing readiness and approval checks."],
            ['Pending Client Documents Follow-Up', 'Published', 'DEMO-010',
                "Review the outstanding onboarding requirement and record the specific missing document or incomplete pages in the existing follow-up. Confirm the responsible staff member and next follow-up date.\n\nUse the approved secure channel when a request is authorized. Receipt alone does not resolve the requirement: link the submitted evidence and obtain the required verification before closing the follow-up."],
            ['Document Verification and Approval Reminder', 'Published', 'DEMO-004',
                "The assigned staff member should review onboarding documents requiring clarification and identify the exact discrepancy for each item. Check client identity, relevant dates, legibility and completeness against the requirement.\n\nAn authorized reviewer must record the validation decision and actionable remarks. Preserve the original submission and link any replacement evidence before marking the requirement satisfied."],
            ['Upcoming Compliance Preparation Review', 'Draft', null,
                "Prepare a proposed review agenda covering applicable requirements, reporting periods, evidence readiness and unresolved client questions. Assigned staff should identify work that needs an earlier internal preparation target.\n\nThe Office Manager must validate the agenda and confirm the review schedule before publication. Verify official applicability and due dates separately; internal preparation targets do not replace statutory deadlines."],
            ['Internal Document Submission Deadlines', 'Archived', null,
                "The prior internal document collection cycle is closed. This notice retains the instructions used to organize submissions by client and reporting period and to escalate missing evidence before review.\n\nFor current work, consult the active requirement and its recorded internal target. Do not reuse a previous collection target as an official filing deadline, and retain unresolved follow-ups until the evidence is verified."],
            ['Outstanding Compliance Follow-Up Review', 'Draft', 'DEMO-006',
                "The assigned Office Manager should review open compliance items, confirm supporting document availability and record the next action for each unresolved requirement. Reconcile the reporting period and assignee with the existing compliance record.\n\nBefore publication, confirm internal preparation targets and independently verify applicable official deadlines. Retain completion evidence before closing an item; a planned follow-up is not proof of completion."],
            ['Updated Knowledge Base Procedures and Templates', 'Published', null,
                "The Knowledge Base contains procedures, checklists and templates for onboarding, document validation, journal preparation, vouchers and compliance monitoring. Use the relevant procedure when preparing work and adapt template fields to the authorized client record.\n\nOffice Managers should review completed checklists for unresolved items and direct procedural questions to the Owner. Keep client-specific details in the client workspace and verify official requirements separately when applying compliance guidance."],
        ];
    }
}
