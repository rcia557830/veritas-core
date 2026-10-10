<?php

/*
|--------------------------------------------------------------------------
| Client onboarding policy
|--------------------------------------------------------------------------
|
| Onboarding readiness is calculated centrally by
| App\Services\OnboardingReadiness from onboarding-scoped document
| requirements. This file documents the CBL / COR type mapping and the
| computed readiness labels so the profile page, checklist, and dashboard
| always agree.
|
| CBL = City / Mayor's Business License (document type "Business Permit").
| COR = Certificate of Registration.
|
*/

return [

    // Requirement document types that identify CBL and COR onboarding items.
    'cbl_types' => ['Business Permit'],
    'cor_types' => ['Certificate of Registration'],

    // Scope value stored on document_requirements for onboarding items.
    'onboarding_scope' => 'onboarding',
    'periodic_scope' => 'periodic',

];
