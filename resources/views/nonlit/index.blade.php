@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto p-4">
    
  @php $loan = $case->loanAccount; @endphp

    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">

        {{-- KIRI: Judul --}}
        <div>
            <h1 class="text-3xl font-bold text-slate-900">
                Non-Litigasi
            </h1>
            @if($case->closed_at)
                <span class="ml-2 inline-flex px-2 py-0.5 rounded-full
                            bg-slate-200 text-slate-700 text-[10px] font-semibold">
                    CLOSED
                </span>
            @endif
        </div>

        {{-- KANAN: Tombol --}}
        <div class="flex items-center gap-2 md:justify-end">

            {{-- Kembali --}}
            <a href="{{ route('cases.show', $case) }}"
            class="inline-flex items-center gap-1 px-3 py-2 rounded-lg
                    border border-slate-200 text-slate-700
                    hover:bg-slate-50 text-xs font-semibold whitespace-nowrap">
                <span>←</span>
                <span>Kembali ke Detail Kasus</span>
            </a>

            {{-- Ajukan Non-Litigasi --}}
            @if($case->closed_at)
                <button type="button"
                    class="inline-flex items-center gap-1 px-4 py-2 rounded-lg
                        bg-slate-300 text-slate-500 text-xs font-semibold
                        cursor-not-allowed whitespace-nowrap"
                    title="Kasus sudah ditutup, tidak dapat mengajukan Non-Litigasi">
                    <span>＋</span>
                    <span>Ajukan Non-Litigasi</span>
                </button>
            @else
                <a href="{{ route('cases.nonlit.create', $case) }}"
                class="inline-flex items-center gap-1 px-4 py-2 rounded-lg
                        bg-msa-blue text-white text-xs font-semibold
                        hover:bg-blue-900 whitespace-nowrap">
                    <span>＋</span>
                    <span>Ajukan Non-Litigasi</span>
                </a>
            @endif

        </div>
    </div>

  <div class="mt-4 bg-white rounded shadow overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-gray-50">
        <tr>
          <th class="text-left p-3">Tipe</th>
          <th class="text-left p-3">Status</th>
          <th class="text-left p-3">Pengusul</th>
          <th class="text-left p-3">Tanggal</th>
          <th class="text-right p-3">Aksi</th>
        </tr>
      </thead>
      <tbody>
        @forelse($items as $it)
          <tr class="border-t">
            <td class="p-3">{{ $it->action_type }}</td>
            <td class="p-3">{{ strtoupper($it->status) }}</td>
            <td class="p-3">{{ $it->proposed_by_name }}</td>
            <td class="p-3">{{ optional($it->proposal_at)->format('d/m/Y H:i') }}</td>
            <td class="p-3 text-right">
              <a class="px-3 py-1.5 border rounded text-xs" href="{{ route('nonlit.show', $it) }}">Detail</a>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="p-4 text-gray-500">Belum ada usulan non-litigasi.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
