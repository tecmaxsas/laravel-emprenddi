@php
    $activeId = session('accountant_active_company_id');
    $active = $activeId ? \App\Models\Company::find($activeId) : null;
@endphp

@if ($active)
    <a
        href="{{ url('contador/select-company') }}"
        style="display:inline-flex; align-items:center; gap:8px; padding:6px 12px; border-radius:8px; background:rgb(236,253,245); color:rgb(6,95,70); border:1px solid rgb(167,243,208); font-size:13px; font-weight:600; text-decoration:none; order: -1;"
        title="Cambiar a otra empresa"
    >
        <svg style="width:16px; height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        <span>{{ $active->legal_name }}</span>
        <svg style="width:12px; height:12px; opacity:0.6;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
    </a>
@endif
