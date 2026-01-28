<?php

return [
    'marketing_snapshot' => [
        // ✅ tabel snapshot detail per rekening
        'table'       => env('KPI_MKT_SNAPSHOT_TABLE', 'loan_account_snapshots_monthly'),

        // ✅ kolom periode snapshot
        'period_col'  => env('KPI_MKT_SNAPSHOT_PERIOD_COL', 'snapshot_month'),

        // ✅ kolom AO
        'ao_col'      => env('KPI_MKT_SNAPSHOT_AO_COL', 'ao_code'),

        // ✅ kolom outstanding per rekening
        'os_col'      => env('KPI_MKT_SNAPSHOT_OS_COL', 'outstanding'),

        // ✅ kolom account number untuk hitung NOA
        'account_col' => env('KPI_MKT_SNAPSHOT_ACCOUNT_COL', 'account_no'),

        // ✅ kolom tanggal posisi sumber (opsional)
        'source_pos_col' => env('KPI_MKT_SNAPSHOT_SOURCE_POS_COL', 'source_position_date'),

        // ✅ NOA dihitung pakai distinct account_no (lebih aman)
        'noa_distinct' => env('KPI_MKT_SNAPSHOT_NOA_DISTINCT', true),
    ],

    'cap_pct' => [
        'os'  => env('KPI_MKT_CAP_OS', 120),
        'noa' => env('KPI_MKT_CAP_NOA', 120),
    ],
];
