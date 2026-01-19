@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto p-4">
  @php $loan = $case->loanAccount; @endphp

    <div class="mt-4 bg-white rounded-2xl shadow-sm border border-slate-100 p-4">
    <div class="text-xs text-slate-500">Debitur</div>
    <div class="text-lg font-semibold text-slate-800">
        {{ $loan?->customer_name ?? '-' }}
    </div>
    <div class="text-xs text-slate-500 mt-1">
        Rek: <span class="font-mono">{{ $loan?->account_no ?? '-' }}</span> •
        CIF: <span class="font-mono">{{ $loan?->cif ?? '-' }}</span>
    </div>
    <div class="text-xs text-slate-500 mt-1">
        AO: {{ $loan?->ao_name ?? '-' }} ({{ $loan?->ao_code ?? '-' }})
    </div>
    </div>
  
  <div class="flex items-center justify-between">
    <h1 class="text-xl font-bold">Detail Non-Litigasi #{{ $nonLit->id }}</h1>
    <a href="{{ route('cases.nonlit.index', $case) }}" class="px-3 py-2 border rounded text-sm">Kembali</a>
  </div>

  @if(session('error')) <div class="mt-3 p-3 bg-red-50 text-red-700 rounded">{{ session('error') }}</div> @endif
  @if(session('success')) <div class="mt-3 p-3 bg-green-50 text-green-700 rounded">{{ session('success') }}</div> @endif

  <div class="mt-4 bg-white rounded shadow p-4">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
      <div><b>Status:</b> {{ strtoupper($nonLit->status) }}</div>
      <div><b>Tipe:</b> {{ $nonLit->action_type }}</div>
      <div><b>Pengusul:</b> {{ $nonLit->proposed_by_name }}</div>
      <div><b>Proposal At:</b> {{ optional($nonLit->proposal_at)->format('d/m/Y H:i') }}</div>
      <div class="md:col-span-2"><b>Ringkasan:</b> {{ $nonLit->proposal_summary }}</div>
      <div class="md:col-span-2"><b>Detail:</b>
        <pre class="mt-2 p-2 bg-gray-50 rounded overflow-auto text-xs">{{ is_array($nonLit->proposal_detail) ? json_encode($nonLit->proposal_detail, JSON_PRETTY_PRINT) : $nonLit->proposal_detail }}</pre>
      </div>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
      @if($nonLit->status === \App\Models\NonLitigationAction::STATUS_DRAFT)
        <a href="{{ route('nonlit.edit', $nonLit) }}" class="px-3 py-2 border rounded text-sm">Edit Draft</a>

        <form method="POST" action="{{ route('nonlit.submit', $nonLit) }}">
          @csrf
          <button class="px-3 py-2 rounded bg-indigo-600 text-white text-sm">Submit</button>
        </form>
      @endif

      @can('approve', $nonLit)
        @if($nonLit->status === \App\Models\NonLitigationAction::STATUS_SUBMITTED)
            <div class="mt-4 space-y-2">

                <form method="POST" action="{{ route('nonlit.approve', $nonLit) }}" class="flex gap-2 items-end">
                    @csrf
                    <input
                        type="date"
                        name="monitoring_next_due"
                        class="border rounded p-2 text-sm"
                        placeholder="Monitoring due"
                    >
                    <input
                        type="text"
                        name="approval_notes"
                        class="border rounded p-2 text-sm"
                        placeholder="Catatan (opsional)"
                    >
                    <button class="px-3 py-2 rounded bg-green-600 text-white text-sm">
                        Approve
                    </button>
                </form>

                <form method="POST" action="{{ route('nonlit.reject', $nonLit) }}" class="flex gap-2 items-end">
                    @csrf
                    <input
                        type="text"
                        name="rejection_notes"
                        class="border rounded p-2 text-sm"
                        placeholder="Alasan penolakan"
                        required
                    >
                    <button class="px-3 py-2 rounded bg-red-600 text-white text-sm">
                        Reject
                    </button>
                </form>

            </div>
        @endif
    @endcan

    @if($nonLit->monitoring_next_due)
        <span class="inline-flex px-2 py-1 text-xs rounded bg-orange-100 text-orange-700">
            Monitoring Due: {{ $nonLit->monitoring_next_due->format('d-m-Y') }}
        </span>
    @endif

  </div>
</div>
@endsection
