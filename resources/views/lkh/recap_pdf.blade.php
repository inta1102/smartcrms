<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>LKH Harian</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 12px;
            color: #111827;
        }

        .title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 6px;
        }

        .meta {
            font-size: 12px;
            margin-bottom: 2px;
        }

        .box {
            border: 1px solid #d1d5db;
            padding: 10px;
            margin-top: 12px;
            margin-bottom: 12px;
            background: #f9fafb;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }

        th, td {
            border: 1px solid #d1d5db;
            padding: 8px;
            vertical-align: top;
            text-align: left;
        }

        th {
            background: #f3f4f6;
            font-weight: bold;
        }

        .muted {
            color: #6b7280;
            font-size: 11px;
        }

        .signatures {
            width: 100%;
            margin-top: 40px;
            table-layout: fixed;
        }

        .signatures td {
            border: none;
            text-align: center;
            width: 25%;
            padding-top: 30px;
        }

        .sign-space {
            height: 55px;
        }
    </style>
</head>
<body>
@php
    $roleValue = strtoupper((string)($role ?? $rkh->user?->level?->value ?? $rkh->user?->level ?? 'RO'));

    $roleLabelMap = [
        'RO'   => 'RO',
        'FE'   => 'FE',
        'AO'   => 'AO',
        'SO'   => 'SO',
        'BE'   => 'BE',
        'TLRO' => 'TLRO',
        'TLFE' => 'TLFE',
        'TLUM' => 'TLUM',
        'TLSO' => 'TLSO',
        'TLBE' => 'TLBE',
        'KSLR' => 'KSLR',
        'KSFE' => 'KSFE',
        'KBL'  => 'KBL',
    ];

    $roleLabel = $roleLabelMap[$roleValue] ?? $roleValue;

    $signLabels = match ($roleValue) {
        'RO'   => ['RO', 'TL', 'Kasi', 'Kabag'],
        'FE'   => ['FE', 'TL', 'Kasi', 'Kabag'],
        'AO'   => ['AO', 'TL', 'Kasi', 'Kabag'],
        'SO'   => ['SO', 'TL', 'Kasi', 'Kabag'],
        'BE'   => ['BE', 'TL', 'Kasi', 'Kabag'],
        default => [$roleLabel, 'Atasan 1', 'Atasan 2', 'Pimpinan'],
    };
@endphp

<div class="title">LKH Harian ({{ $roleLabel }})</div>

<div class="meta">Tanggal: <b>{{ $rkh->tanggal->format('d-m-Y') }}</b></div>
<div class="meta">{{ $roleLabel }}: <b>{{ $rkh->user->name }}</b></div>
<div class="meta">Status RKH: <b>{{ strtoupper($rkh->status) }}</b></div>
<div class="meta">Terisi: <b>{{ $stats['lkh_terisi'] }}</b> / {{ $stats['total_kegiatan'] }}</div>

<table>
    <thead>
        <tr>
            <th style="width: 11%;">Jam</th>
            <th style="width: 22%;">Nasabah</th>
            <th style="width: 8%;">Kolek</th>
            <!-- <th style="width: 21%;">Kegiatan</th> -->
            <th style="width: 23%;">Hasil</th>
            <th style="width: 15%;">Tindak Lanjut</th>
        </tr>
    </thead>
    <tbody>
        @foreach($rows as $r)
            <tr>
                <td>{{ $r['jam'] }}</td>
                <td>
                    {{ $r['nasabah'] }}
                    @if(!empty($r['networking']))
                        <div class="muted">
                            Networking: {{ $r['networking']['nama_relasi'] }}
                            ({{ $r['networking']['jenis_relasi'] }})
                        </div>
                    @endif
                </td>
                <td>{{ $r['kolek'] }}</td>
                <!-- <td>
                    <div><b>{{ $r['jenis'] }}</b></div>
                    <div>{{ $r['tujuan'] }}</div>
                    <div class="muted">{{ $r['area'] }}</div>
                </td> -->
                <td>
                    @if(is_null($r['is_visited']) && empty($r['hasil']))
                        <span class="muted">Belum diisi</span>
                    @else
                        @if($r['is_visited'] === false)
                            <div><b>Tidak dikunjungi</b></div>
                        @elseif(!empty($r['source_visit_done']))
                            <div><b>Visit DONE</b></div>
                        @endif

                        <div style="white-space: pre-line;">{{ $r['hasil'] ?: '-' }}</div>

                        @if(!empty($r['respon']))
                            <div class="muted" style="white-space: pre-line; margin-top: 4px;">
                                {{ $r['respon'] }}
                            </div>
                        @endif
                    @endif
                </td>
                <td style="white-space: pre-line;">{{ $r['tindak_lanjut'] ?: '-' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="signatures">
    <tr>
        @foreach($signLabels as $label)
            <td>{{ $label }}</td>
        @endforeach
    </tr>
    <tr>
        @foreach($signLabels as $idx => $label)
            <td class="sign-space"></td>
        @endforeach
    </tr>
    <tr>
        @foreach($signLabels as $idx => $label)
            <td>
                @if($idx === 0)
                    ( {{ $rkh->user->name }} )
                @else
                    ( .......................... )
                @endif
            </td>
        @endforeach
    </tr>
</table>

</body>
</html>