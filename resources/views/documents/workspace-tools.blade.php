<div class="workspace-heading">
    <div><h2>Client documents</h2><p class="subtext">Track required documents, review submissions and follow up in one workspace.</p></div>
    <div class="actions">
        @can('create', \App\Models\Document::class)<a class="btn btn-primary" href="{{ route('documents.create', request()->only('client_id')) }}">Upload / register document</a>@endcan
        @if(auth()->user()->can('viewAny', \App\Models\DocumentRequirement::class) || auth()->user()->can('viewAny', \App\Models\DocumentRequirementTemplate::class))
        <details class="workspace-tools"><summary>Requirements and setup</summary><div class="actions mt-2">
            @can('viewAny', \App\Models\DocumentRequirement::class)<a href="{{ route('requirements.index', request()->only('client_id')) }}">Document requirements</a>@endcan
            @can('viewAny', \App\Models\DocumentRequirementTemplate::class)<a href="{{ route('requirements.templates.index') }}">Requirement templates</a>@endcan
            @if(request('client_id'))
                @can('viewAny', \App\Models\DocumentRequirement::class)<a href="{{ route('requirements.checklist', request('client_id')) }}">Client checklist</a>@endcan
                @can('viewAny', \App\Models\Client::class)<a href="{{ route('clients.onboarding.checklist', request('client_id')) }}">Onboarding requirements</a>@endcan
            @endif
        </div></details>
        @endif
    </div>
</div>

@if($clientId = request('client_id'))
    @php
        $selectedClient = \App\Services\Access::query(\App\Models\Client::class)->find($clientId);
        $docStats = $selectedClient ? \App\Services\DocumentCompleteness::forClient($selectedClient) : null;
        $submittedCount = $selectedClient ? \App\Services\Access::query(\App\Models\Document::class)->where('client_id', $clientId)->count() : 0;
    @endphp
    @if($selectedClient && $docStats)
    <section class="panel panel-pad mb-4 client-doc-overview">
        <div class="toolbar mb-2">
            <div>
                <h3 class="section-title mb-0">{{ $selectedClient->business_name }}</h3>
                <span class="subtext">Client document completeness overview</span>
            </div>
            <div class="actions">
                @if($docStats['not_configured'])
                    <x-badge status="Not configured"/>
                @else
                    <span class="badge-status tone-success">{{ $docStats['percentage'] }}% Complete</span>
                @endif
                @can('viewAny', \App\Models\DocumentRequirement::class)
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('requirements.checklist', $selectedClient) }}">Open checklist</a>
                @endcan
            </div>
        </div>
        @if(!$docStats['not_configured'])
            <progress class="metric-progress" aria-label="Document completeness" value="{{ $docStats['percentage'] }}" max="100"></progress>
        @else
            <p class="subtext">No active required documents configured for this client.</p>
        @endif
        <div class="row g-2 mt-3">
            <div class="col-6 col-md-4 col-lg">
                <div class="mini-stat-card p-2 text-center border rounded">
                    <span class="subtext d-block">Required</span>
                    <strong>{{ $docStats['total_required'] }}</strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg">
                <div class="mini-stat-card p-2 text-center border rounded">
                    <span class="subtext d-block">Submitted</span>
                    <strong>{{ $submittedCount }}</strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg">
                <div class="mini-stat-card p-2 text-center border rounded">
                    <span class="subtext d-block">Awaiting</span>
                    <strong class="text-warning-emphasis">{{ $docStats['awaiting'] }}</strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg">
                <div class="mini-stat-card p-2 text-center border rounded">
                    <span class="subtext d-block">Missing</span>
                    <strong class="text-danger">{{ $docStats['missing'] }}</strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg">
                <div class="mini-stat-card p-2 text-center border rounded">
                    <span class="subtext d-block">Incomplete</span>
                    <strong class="text-danger">{{ $docStats['incomplete'] }}</strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg">
                <div class="mini-stat-card p-2 text-center border rounded">
                    <span class="subtext d-block">Clarification</span>
                    <strong class="text-warning-emphasis">{{ $docStats['clarification'] }}</strong>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg">
                <div class="mini-stat-card p-2 text-center border rounded">
                    <span class="subtext d-block">Verified</span>
                    <strong class="text-success">{{ $docStats['verified'] }}</strong>
                </div>
            </div>
        </div>
    </section>
    @endif
@endif

