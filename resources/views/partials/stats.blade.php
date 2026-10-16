@php
    $metricLabels = $source==='DMS' && !($personalWorkspace??false) ? ['documents'=>['School documents','Across all four workspaces'],'accounts'=>['Active staff','Scoped to '.$viewer->campus.' campus'],'connectors'=>['Connected systems','EMS · PMS · ES · OAS'],'blocked'=>['Files awaiting attention','Review scan status']] : ['documents'=>['Total documents','In this workspace'],'shared'=>['Shared documents','With active staff access'],'history'=>['History received','Immutable source events'],'blocked'=>['Files awaiting scan','Blocked until cleared']];
    if ($personalWorkspace??false) $metricLabels = ['documents'=>['My documents','In your personal workspace'],'folders'=>['Folders','Your document organization'],'shared'=>['Shared documents','With active staff access'],'blocked'=>['Files awaiting scan','Blocked until cleared']];
@endphp
<div class="stats-grid">
    @foreach ($metricLabels as $key=>[$label,$note])
        <article class="stat-card">
            <h2>{{ $label }}</h2>
            <strong
                >{{ isset($stats[$key]) ? number_format($stats[$key]) : '—' }}
                @if ($key==='connectors') / 4 @endif
            </strong>
            <p>{{ $note }}</p>
        </article>
    @endforeach
</div>
