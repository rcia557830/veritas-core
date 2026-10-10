<?php

/*
|--------------------------------------------------------------------------
| Compliance deadline policy
|--------------------------------------------------------------------------
|
| The client document submission deadline is derived from the official
| filing deadline. The research documentation does not establish whether
| RBCIA means calendar days or working days, so the calendar-day default
| below is a PROTOTYPE ASSUMPTION pending RBCIA confirmation. It can be
| changed without a migration by setting these environment variables.
|
*/

return [

    // Number of days before the official filing deadline that internal client
    // document submission is due. Default: 10 (prototype assumption).
    'submission_lead_days' => (int) env('COMPLIANCE_SUBMISSION_LEAD_DAYS', 10),

    // 'calendar' subtracts calendar days. 'business' skips weekends (Saturdays
    // and Sundays). Public holidays are NOT excluded unless a verified holiday
    // calendar and an explicit policy are supplied.
    'submission_lead_basis' => env('COMPLIANCE_SUBMISSION_LEAD_BASIS', 'calendar'),

    // Urgency windows (in days) used for calculated indicators and internal
    // notifications. A deadline is "approaching" when it is between today and
    // today + window days (inclusive).
    'filing_approach_days' => (int) env('COMPLIANCE_FILING_APPROACH_DAYS', 10),
    'submission_approach_days' => (int) env('COMPLIANCE_SUBMISSION_APPROACH_DAYS', 10),

];
