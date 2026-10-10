<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Document;
use App\Models\DocumentRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class DocumentCompleteness
{
    public const STATE_NOT_SUBMITTED = 'not_submitted';

    public const STATE_AWAITING = 'awaiting';

    public const STATE_INCOMPLETE = 'incomplete';

    public const STATE_CLARIFICATION = 'clarification';

    public const STATE_VERIFIED = 'verified';

    /** @return Collection<int, Document> */
    public static function documentsOf(DocumentRequirement $requirement): Collection
    {
        return $requirement->relationLoaded('documents')
            ? $requirement->documents
            : $requirement->documents()->get();
    }

    public static function representativeDocument(DocumentRequirement $requirement): ?Document
    {
        $documents = self::documentsOf($requirement);

        // Accepted evidence satisfies the requirement; otherwise use the latest link.
        return $documents->first(fn ($document) => $document->status === 'Approved') ?? $documents->first();
    }

    public static function stateOf(DocumentRequirement $requirement): string
    {
        $documents = self::documentsOf($requirement);
        if ($documents->isEmpty()) {
            return self::STATE_NOT_SUBMITTED;
        }

        return match (self::representativeDocument($requirement)->status) {
            'Approved' => self::STATE_VERIFIED,
            'Rejected' => self::STATE_INCOMPLETE,
            'Needs Clarification' => self::STATE_CLARIFICATION,
            default => self::STATE_AWAITING, // Submitted, Under Review, Reviewed
        };
    }

    public static function label(string $state): string
    {
        return [
            self::STATE_NOT_SUBMITTED => 'Not submitted',
            self::STATE_AWAITING => 'Awaiting verification',
            self::STATE_INCOMPLETE => 'Incomplete',
            self::STATE_CLARIFICATION => 'Needs clarification',
            self::STATE_VERIFIED => 'Verified',
        ][$state] ?? ucwords(str_replace('_', ' ', $state));
    }

    /**
     * Mandatory completeness for a single client. Optional requirements are
     * excluded from both numerator and denominator; inactive requirements are
     * excluded entirely.
     */
    public static function forClient(Client $client): array
    {
        $requirements = $client->documentRequirements()
            ->where('is_active', true)
            ->with('documents')
            ->get();

        $totals = [
            'total_required' => 0,
            'verified' => 0,
            'awaiting' => 0,
            'incomplete' => 0,
            'clarification' => 0,
            'missing' => 0,
        ];

        foreach ($requirements as $requirement) {
            if (! $requirement->is_required) {
                continue;
            }
            $totals['total_required']++;
            $state = self::stateOf($requirement);
            if ($state === self::STATE_VERIFIED) {
                $totals['verified']++;
            } elseif ($state === self::STATE_AWAITING) {
                $totals['awaiting']++;
            } elseif ($state === self::STATE_INCOMPLETE) {
                $totals['incomplete']++;
            } elseif ($state === self::STATE_CLARIFICATION) {
                $totals['clarification']++;
            } else {
                $totals['missing']++;
            }
        }

        $totals['not_configured'] = $totals['total_required'] === 0;
        $totals['percentage'] = $totals['not_configured']
            ? null
            : (int) round(($totals['verified'] / $totals['total_required']) * 100);

        return $totals;
    }

    /**
     * Organization-wide summary across the clients the user may access.
     * These numbers back both the monitoring page and the dashboard.
     */
    public static function organization(?User $user = null): array
    {
        $user ??= auth()->user();
        $clientIds = Access::query(Client::class, $user)->pluck('id');

        $totals = [
            'clients_missing' => 0,
            'missing' => 0,
            'awaiting' => 0,
            'incomplete' => 0,
            'clarification' => 0,
            'verified' => 0,
        ];

        $clientsWithMissing = [];
        DocumentRequirement::whereIn('client_id', $clientIds)
            ->where('is_active', true)
            ->where('is_required', true)
            ->with('documents')
            ->chunkById(200, function ($requirements) use (&$totals, &$clientsWithMissing) {
                foreach ($requirements as $requirement) {
                    $state = self::stateOf($requirement);
                    if ($state === self::STATE_VERIFIED) {
                        $totals['verified']++;
                    } elseif ($state === self::STATE_AWAITING) {
                        $totals['awaiting']++;
                    } elseif ($state === self::STATE_INCOMPLETE) {
                        $totals['incomplete']++;
                    } elseif ($state === self::STATE_CLARIFICATION) {
                        $totals['clarification']++;
                    } else {
                        $totals['missing']++;
                        $clientsWithMissing[$requirement->client_id] = true;
                    }
                }
            });

        $totals['clients_missing'] = count($clientsWithMissing);

        return $totals;
    }
}
