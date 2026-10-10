<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DocumentRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Centralized client onboarding readiness calculation.
 *
 * Onboarding requirements are document requirements whose `scope` is
 * "onboarding" (CBL, COR, and any other explicitly configured registration
 * documents). Only active, required onboarding requirements contribute to
 * readiness; unrelated accounting-period requirements are excluded.
 *
 * The calculated state is derived (never stored) so the client profile,
 * checklist, and dashboard always agree. Onboarding completion is recorded
 * separately on the client via `onboarded_at` / `onboarded_by` and never
 * reinterprets the client's operational `status`.
 */
class OnboardingReadiness
{
    public const STATE_ONBOARDED = 'onboarded';

    public const STATE_NOT_CONFIGURED = 'not_configured';

    public const STATE_NOT_STARTED = 'not_started';

    public const STATE_IN_PROGRESS = 'in_progress';

    public const STATE_AWAITING_VERIFICATION = 'awaiting_verification';

    public const STATE_NEEDS_CLARIFICATION = 'needs_clarification';

    public const STATE_READY = 'ready_for_activation';

    public static function onboardingScope(): string
    {
        return (string) config('onboarding.onboarding_scope', 'onboarding');
    }

    public static function isCbl(DocumentRequirement $requirement): bool
    {
        return in_array($requirement->type, config('onboarding.cbl_types', ['Business Permit']), true);
    }

    public static function isCor(DocumentRequirement $requirement): bool
    {
        return in_array($requirement->type, config('onboarding.cor_types', ['Certificate of Registration']), true);
    }

    /** @return Collection<int, DocumentRequirement> */
    public static function requirements(Client $client): Collection
    {
        return $client->documentRequirements()
            ->where('is_active', true)
            ->where('scope', self::onboardingScope())
            ->with('documents')
            ->orderBy('name')
            ->get();
    }

    /**
     * Full readiness snapshot. Pass an already-loaded requirements collection
     * to avoid a second query when bulk-calculating across many clients.
     */
    public static function calculate(Client $client, ?Collection $requirements = null): array
    {
        $requirements = $requirements ?? self::requirements($client);
        $required = $requirements->filter(fn (DocumentRequirement $r) => $r->is_required)->values();

        $counts = ['verified' => 0, 'awaiting' => 0, 'incomplete' => 0, 'clarification' => 0, 'missing' => 0];
        foreach ($required as $requirement) {
            $state = DocumentCompleteness::stateOf($requirement);
            $counts[match ($state) {
                DocumentCompleteness::STATE_VERIFIED => 'verified',
                DocumentCompleteness::STATE_AWAITING => 'awaiting',
                DocumentCompleteness::STATE_INCOMPLETE => 'incomplete',
                DocumentCompleteness::STATE_CLARIFICATION => 'clarification',
                default => 'missing',
            }]++;
        }

        $total = $required->count();
        $onboarded = $client->onboarded_at !== null;
        $notConfigured = $total === 0;
        $state = self::computeState($onboarded, $notConfigured, $counts, $total);

        return [
            'state' => $state,
            'counts' => $counts,
            'total' => $total,
            'onboarded' => $onboarded,
            'not_configured' => $notConfigured,
            'percentage' => $notConfigured ? null : (int) round(($counts['verified'] / $total) * 100),
            'requirements' => $requirements,
            'required' => $required,
        ];
    }

    public static function state(Client $client): string
    {
        return self::calculate($client)['state'];
    }

    public static function label(string $state): string
    {
        return [
            self::STATE_ONBOARDED => 'Onboarded',
            self::STATE_NOT_CONFIGURED => 'Not configured',
            self::STATE_NOT_STARTED => 'Not started',
            self::STATE_IN_PROGRESS => 'In progress',
            self::STATE_AWAITING_VERIFICATION => 'Awaiting verification',
            self::STATE_NEEDS_CLARIFICATION => 'Needs clarification',
            self::STATE_READY => 'Ready for activation',
        ][$state] ?? ucwords(str_replace('_', ' ', $state));
    }

    /**
     * Whether onboarding can be completed for a client, plus the reasons it
     * cannot. Configured requirements must be verified; only a client with no
     * configured onboarding requirements is exemptable (with a justification).
     */
    public static function canActivate(Client $client): array
    {
        $calc = self::calculate($client);
        $state = $calc['state'];

        if (in_array($state, [self::STATE_ONBOARDED, self::STATE_READY], true)) {
            return ['ok' => true, 'state' => $state, 'blockers' => [], 'exemptable' => false];
        }

        if ($state === self::STATE_NOT_CONFIGURED) {
            return ['ok' => false, 'state' => $state, 'blockers' => ['No onboarding requirements are configured for this client.'], 'exemptable' => true];
        }

        $blockers = [];
        foreach ($calc['required'] as $requirement) {
            $s = DocumentCompleteness::stateOf($requirement);
            if ($s !== DocumentCompleteness::STATE_VERIFIED) {
                $blockers[] = $requirement->name.' ('.DocumentCompleteness::label($s).')';
            }
        }

        return ['ok' => false, 'state' => $state, 'blockers' => $blockers, 'exemptable' => false];
    }

    /**
     * Organization-wide onboarding buckets, permission-scoped to the user.
     */
    public static function organization(?User $user = null): array
    {
        $user ??= auth()->user();
        $clients = Access::query(Client::class, $user)->with(['documentRequirements' => function ($q) {
            $q->where('is_active', true)->where('scope', self::onboardingScope())->with('documents');
        }])->get();

        $buckets = [
            self::STATE_ONBOARDED => 0,
            self::STATE_NOT_CONFIGURED => 0,
            self::STATE_NOT_STARTED => 0,
            self::STATE_IN_PROGRESS => 0,
            self::STATE_AWAITING_VERIFICATION => 0,
            self::STATE_NEEDS_CLARIFICATION => 0,
            self::STATE_READY => 0,
        ];
        $clientsMissing = 0;

        foreach ($clients as $client) {
            $calc = self::calculate($client, $client->documentRequirements);
            $buckets[$calc['state']]++;
            if ($calc['counts']['missing'] > 0) {
                $clientsMissing++;
            }
        }

        $buckets['clients_missing'] = $clientsMissing;
        $buckets['pending'] = $buckets[self::STATE_NOT_STARTED]
            + $buckets[self::STATE_IN_PROGRESS]
            + $buckets[self::STATE_AWAITING_VERIFICATION]
            + $buckets[self::STATE_NEEDS_CLARIFICATION];

        return $buckets;
    }

    /**
     * Count of filled-in profile fields used for the registration progress bar.
     */
    public static function profileProgress(Client $client): array
    {
        $fields = ['business_name', 'business_type', 'contact_person', 'email', 'phone', 'tin', 'address'];
        $missing = [];
        $complete = 0;
        foreach ($fields as $field) {
            $value = trim((string) ($client->{$field} ?? ''));
            if ($value === '') {
                $missing[] = $field;
            } else {
                $complete++;
            }
        }

        return ['complete' => $complete, 'total' => count($fields), 'missing' => $missing];
    }

    /**
     * Reviewing staff and verification date for a verified requirement,
     * derived from the approval audit entry for its approved document.
     */
    public static function verificationInfo(DocumentRequirement $requirement): array
    {
        $document = DocumentCompleteness::representativeDocument($requirement);
        if (! $document || $document->status !== 'Approved') {
            return ['reviewer' => null, 'verified_at' => null];
        }

        $audit = AuditLog::where('module', 'documents')
            ->where('record_id', $document->id)
            ->where('action', 'document.validated')
            ->where('description', 'like', '%Approved%')
            ->latest('id')
            ->first();

        return ['reviewer' => $audit?->user?->name, 'verified_at' => $audit?->created_at];
    }

    private static function computeState(bool $onboarded, bool $notConfigured, array $counts, int $total): string
    {
        if ($onboarded) {
            return self::STATE_ONBOARDED;
        }
        if ($notConfigured) {
            return self::STATE_NOT_CONFIGURED;
        }
        if ($counts['clarification'] > 0) {
            return self::STATE_NEEDS_CLARIFICATION;
        }
        if ($counts['verified'] === $total) {
            return self::STATE_READY;
        }
        if ($counts['missing'] === 0 && $counts['incomplete'] === 0 && $counts['clarification'] === 0 && $counts['verified'] === 0) {
            return self::STATE_AWAITING_VERIFICATION;
        }
        if ($counts['missing'] === $total) {
            return self::STATE_NOT_STARTED;
        }

        return self::STATE_IN_PROGRESS;
    }
}
