<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Final Audit Summary - HT Execution</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        .h1 { font-size: 16px; font-weight: bold; margin-bottom: 2px; }
        .muted { color: #666; }
        .box { border: 1px solid #ddd; padding: 10px; border-radius: 6px; margin-top: 10px; }
        table { width:100%; border-collapse: collapse; }
        td, th { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
        th { background:#f3f4f6; text-align:left; }
        .right { text-align:right; }
        .badge { display:inline-block; padding:2px 8px; border:1px solid #999; border-radius: 999px; font-size: 11px; }
    </style>
</head>
<body>

<div class="h1">Final Audit Summary (Eksekusi Hak Tanggungan)</div>
<div class="muted">
    Generated at: {{ $generatedAt->format('d/m/Y H:i') }} |
    By: {{ $generatedBy?->name ?? '-' }}
</div>

<div class="box">
    <table>
        <tr>
            <th>Legal Case</th>
            <td>{{ $action->legalCase?->legal_case_no ?? '-' }}</td>
            <th>Status</th>
            <td class="right"><span class="badge">{{ strtoupper((string)$action->status) }}</span></td>
        </tr>
        <tr>
            <th>Debitur</th>
            <td colspan="3">
                {{ $action->legalCase?->debtor_name ?? $action->legalCase?->nplCase?->loanAccount?->customer_name ?? '-' }}
            </td>
        </tr>
        <tr>
            <th>Metode</th>
            <td>{{ $action->htExecution?->method_label ?? ($action->htExecution?->method ?? '-') }}</td>
            <th>Objek/Sertifikat</th>
            <td>{{ $action->htExecution?->land_cert_type ?? '-' }} {{ $action->htExecution?->land_cert_no ?? '' }}</td>
        </tr>
        <tr>
            <th>Owner</th>
            <td>{{ $action->htExecution?->owner_name ?? '-' }}</td>
            <th>Nilai Taksasi</th>
            <td class="right">
                {{ $action->htExecution?->appraisal_value ? number_format((float)$action->htExecution->appraisal_value,0,',','.') : '-' }}
            </td>
        </tr>
        <tr>
            <th>Alamat Objek</th>
            <td colspan="3">{{ $action->htExecution?->object_address ?? '-' }}</td>
        </tr>
    </table>
</div>

<div class="box">
    <div style="font-weight:bold; margin-bottom:6px;">Hasil Final Eksekusi</div>
    @if(!$finalAuction)
        <div class="muted">Belum ada data attempt final.</div>
    @else
        <table>
            <tr>
                <th>Attempt</th>
                <th>Tgl Lelang</th>
                <th>Hasil</th>
                <th class="right">Limit</th>
                <th class="right">Nilai Laku</th>
                <th>Settlement</th>
                <th>Risalah</th>
            </tr>
            <tr>
                <td>#{{ $finalAuction->attempt_no ?? '-' }}</td>
                <td>{{ $finalAuction->auction_date?->format('d/m/Y') ?? '-' }}</td>
                <td>{{ strtoupper((string)$finalAuction->auction_result) }}</td>
                <td class="right">{{ $finalAuction->limit_value ? number_format((float)$finalAuction->limit_value,0,',','.') : '-' }}</td>
                <td class="right">{{ $finalAuction->sold_value ? number_format((float)$finalAuction->sold_value,0,',','.') : '-' }}</td>
                <td>{{ $finalAuction->settlement_date?->format('d/m/Y') ?? '-' }}</td>
                <td>{{ $finalAuction->risalah_file_path ? 'ADA' : 'TIDAK ADA' }}</td>
            </tr>
        </table>
        <div class="muted" style="margin-top:6px;">
            KPKNL: {{ $finalAuction->kpknl_office ?? '-' }} | Reg: {{ $finalAuction->registration_no ?? '-' }}
        </div>
    @endif
</div>

<div class="box">
    <div style="font-weight:bold; margin-bottom:6px;">Kronologi Status (Audit Trail)</div>
    @if(($milestones ?? collect())->isEmpty())
        <div class="muted">Belum ada status log.</div>
    @else
        <table>
            <tr>
                <th>From</th>
                <th>To</th>
                <th>Timestamp</th>
                <th>Remarks</th>
            </tr>
            @foreach($milestones as $m)
                <tr>
                    <td>{{ $m['from'] }}</td>
                    <td>{{ $m['to'] }}</td>
                    <td>{{ $m['at']?->format('d/m/Y H:i') ?? '-' }}</td>
                    <td>{{ $m['remarks'] ?? '-' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
</div>

<div class="muted" style="margin-top:10px; font-size:11px;">
    Catatan: Dokumen ini bersifat read-only untuk kebutuhan audit trail.
</div>

</body>
</html>
