<?php

namespace App\Support;

use App\Models\Account;
use App\Models\User;

final class AuditHistory
{
    /**
     * Map raw audit actions to clear, user-friendly labels.
     */
    public static function label(string $action): string
    {
        return match ($action) {
            'created' => 'Created',
            'updated' => 'Updated',
            'saved' => 'Saved',
            'submit' => 'Submitted for review',
            'review' => 'Reviewed',
            'return' => 'Returned for correction',
            'post' => 'Posted to General Ledger',
            'voucher.created' => 'Voucher created',
            'voucher.updated' => 'Voucher updated',
            'evidence.attached' => 'Supporting document attached',
            'evidence.detached' => 'Supporting document detached',
            'evidence.download' => 'Retained document downloaded',
            'account.activated' => 'Account activated',
            'account.deactivated' => 'Account deactivated',
            'activated' => 'Activated',
            'deactivated' => 'Deactivated',
            'initialized' => 'Initialized from template',
            'item-saved' => 'Template item saved',
            'item-removed' => 'Template item removed',
            'version-created' => 'New template version created',
            'client.updated' => 'Client details updated',
            'document.validated' => 'Document validated',
            'document.reopened' => 'Document review reopened',
            'document.attachment-replaced' => 'Document attachment replaced',
            'compliance.deadline-changed' => 'Compliance deadline adjusted',
            'compliance.filed' => 'Compliance filed',
            'knowledge.published' => 'Knowledge article published',
            'payment' => 'Payment recorded',
            'notice.published' => 'Notice published',
            'notice.saved' => 'Notice saved as draft',
            'notice.archived' => 'Notice archived',
            'archived' => 'Archived',
            'user.created' => 'User created',
            'user.updated' => 'User updated',
            'role.changed' => 'Role updated',
            default => ucfirst(str_replace(['.', '_', '-'], ' ', $action)),
        };
    }

    /**
     * Format any raw JSON snapshots embedded in descriptions into human-readable text.
     */
    public static function description(?string $description): string
    {
        if ($description === null || $description === '') {
            return '';
        }

        // 1. Replace JSON arrays (such as Lines: [...])
        $formatted = preg_replace_callback('/Lines:\s*(\[(?:[^\[\]]|(?R))*\])/s', function (array $match): string {
            $lines = json_decode($match[1], true);
            if (! is_array($lines)) {
                return $match[0];
            }
            if (empty($lines)) {
                return 'No line items';
            }

            $count = count($lines);
            $summaries = [];
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $name = $line['account_name'] ?? (isset($line['account_id']) ? ('Account #'.$line['account_id']) : 'Unknown');
                $debit = isset($line['debit']) && (float) $line['debit'] > 0 ? ('DR '.Money::formatExact($line['debit'])) : null;
                $credit = isset($line['credit']) && (float) $line['credit'] > 0 ? ('CR '.Money::formatExact($line['credit'])) : null;
                $amount = $debit ?? ($credit ?? '₱0.00');
                $summaries[] = $name.' ('.$amount.')';
            }

            return 'Line items ('.$count.'): '.implode(', ', $summaries);
        }, $description);

        // 2. Replace JSON objects (such as Account snapshots or Voucher metadata)
        $formatted = preg_replace_callback('/\{(?:[^"{}]|"(?:\\\\.|[^"\\\\])*")*\}/s', function (array $match): string {
            $data = json_decode($match[0], true);
            if (! is_array($data)) {
                return $match[0];
            }

            // Case A: Chart of Accounts snapshot: code, name, classification
            if (isset($data['code'], $data['name'], $data['classification']) && count($data) <= 4) {
                $parts = [
                    'Code: '.$data['code'],
                    'Name: '.$data['name'],
                    'Classification: '.$data['classification'],
                ];
                if (isset($data['is_active'])) {
                    $parts[] = 'Status: '.($data['is_active'] ? 'Active' : 'Inactive');
                }

                return implode(' · ', $parts);
            }

            // Case B: Voucher metadata snapshot: party, cash_account_id, amount, check_number, check_date
            if (array_key_exists('party', $data) || array_key_exists('amount', $data) || array_key_exists('check_number', $data)) {
                $parts = [];
                if (! empty($data['party'])) {
                    $parts[] = 'Payee: '.$data['party'];
                }
                if (! empty($data['amount'])) {
                    $parts[] = 'Amount: '.Money::formatExact($data['amount']);
                }
                if (! empty($data['cash_account_id'])) {
                    $account = Account::find($data['cash_account_id']);
                    $parts[] = 'Cash account: '.($account ? ($account->code.' - '.$account->name) : ('#'.$data['cash_account_id']));
                }
                if (! empty($data['check_number'])) {
                    $parts[] = 'Check #: '.$data['check_number'];
                }
                if (! empty($data['check_date'])) {
                    $parts[] = 'Check date: '.$data['check_date'];
                }

                return empty($parts) ? 'No additional voucher details' : implode(' · ', $parts);
            }

            // Case C: Generic associative JSON fallback
            $parts = [];
            foreach ($data as $key => $val) {
                if (is_scalar($val) && $val !== '' && $val !== null) {
                    $parts[] = ucwords(str_replace(['_', '-'], ' ', (string) $key)).': '.$val;
                }
            }

            return empty($parts) ? $match[0] : implode(' · ', $parts);
        }, $formatted ?? $description);

        // 3. Resolve user ID references such as "reviewed by #2" to name
        $formatted = preg_replace_callback('/reviewed by #(\d+)/i', function (array $match): string {
            $user = User::find((int) $match[1]);

            return $user ? ('reviewed by '.$user->name) : $match[0];
        }, $formatted ?? $description);

        return $formatted ?? $description;
    }

    /**
     * Compute a human-readable diff comparing the previous and new state.
     */
    public static function diff(?array $before, ?array $after): array
    {
        if (! $before && ! $after) {
            return [];
        }

        $changes = [];

        // 1. Core ledger attributes
        $fieldLabels = [
            'status' => 'Status',
            'transaction_date' => 'Transaction date',
            'reference_number' => 'Reference number',
            'description' => 'Description',
            'accounting_period_id' => 'Accounting period',
        ];

        foreach ($fieldLabels as $field => $label) {
            $oldVal = $before[$field] ?? null;
            $newVal = $after[$field] ?? null;

            if ($oldVal !== null && $newVal !== null && (string) $oldVal !== (string) $newVal) {
                $changes[] = [
                    'field' => $field,
                    'label' => $label,
                    'old' => (string) $oldVal,
                    'new' => (string) $newVal,
                ];
            }
        }

        // 2. Voucher attributes
        $beforeVoucher = $before['voucher'] ?? null;
        $afterVoucher = $after['voucher'] ?? null;

        if ($beforeVoucher || $afterVoucher) {
            $voucherLabels = [
                'type' => 'Voucher type',
                'reference' => 'Voucher reference',
                'amount' => 'Voucher amount',
                'party' => 'Payee / Party',
                'cash_account_id' => 'Cash/bank account',
                'check_number' => 'Check number',
                'check_date' => 'Check date',
            ];

            foreach ($voucherLabels as $field => $label) {
                $oldVal = $beforeVoucher[$field] ?? null;
                $newVal = $afterVoucher[$field] ?? null;

                if ($oldVal !== null && $newVal !== null && (string) $oldVal !== (string) $newVal) {
                    $oldDisplay = $field === 'amount' ? Money::formatExact($oldVal) : (string) $oldVal;
                    $newDisplay = $field === 'amount' ? Money::formatExact($newVal) : (string) $newVal;

                    if ($field === 'cash_account_id') {
                        $oldAccount = Account::find($oldVal);
                        $newAccount = Account::find($newVal);
                        $oldDisplay = $oldAccount ? ($oldAccount->code.' - '.$oldAccount->name) : ('#'.$oldVal);
                        $newDisplay = $newAccount ? ($newAccount->code.' - '.$newAccount->name) : ('#'.$newVal);
                    }

                    $changes[] = [
                        'field' => 'voucher.'.$field,
                        'label' => $label,
                        'old' => $oldDisplay === '' ? '—' : $oldDisplay,
                        'new' => $newDisplay === '' ? '—' : $newDisplay,
                    ];
                }
            }
        }

        // 3. Line totals comparison
        $beforeLines = $before['lines'] ?? null;
        $afterLines = $after['lines'] ?? null;

        if (is_array($beforeLines) && is_array($afterLines)) {
            $beforeDr = array_sum(array_map(fn ($l) => Money::storedCents($l['debit'] ?? 0), $beforeLines));
            $afterDr = array_sum(array_map(fn ($l) => Money::storedCents($l['debit'] ?? 0), $afterLines));

            if ($beforeDr !== $afterDr || count($beforeLines) !== count($afterLines)) {
                $changes[] = [
                    'field' => 'lines',
                    'label' => 'Journal line totals',
                    'old' => count($beforeLines).' lines ('.Money::formatExact(Money::decimal($beforeDr)).')',
                    'new' => count($afterLines).' lines ('.Money::formatExact(Money::decimal($afterDr)).')',
                ];
            }
        }

        return $changes;
    }
}
