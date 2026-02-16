@php
  $rows = $rows ?? collect();
@endphp

{{-- Desktop --}}
<div class="hidden md:block">
  @if($tab === 'score')
    @include('kpi.marketing.ranking.partials.rows._desktop_score', compact('rows','role','mode','resolveRow'))
  @else
    @include('kpi.marketing.ranking.partials.rows._desktop_growth', compact('rows','role','mode','resolveRow'))
  @endif
</div>

{{-- Mobile --}}
<div class="md:hidden">
  @if($tab === 'score')
    @include('kpi.marketing.ranking.partials.rows._mobile_score', compact('rows','role','mode','resolveRow'))
  @else
    @include('kpi.marketing.ranking.partials.rows._mobile_growth', compact('rows','role','mode','resolveRow'))
  @endif
</div>
