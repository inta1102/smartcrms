<?php

return [

    'reminder_whatsapp_to' => env('LEGAL_REMINDER_WA_TO', ''), // contoh: 62812xxxx
    'reminder_whatsapp_to_name' => env('LEGAL_REMINDER_WA_TO_NAME', 'Tim Legal'),
    'reminder_template_id' => env('LEGAL_REMINDER_TEMPLATE_ID', ''),
    
    'bottleneck' => [
        'sent_not_received_days' => env('LEGAL_SENT_NOT_RECEIVED_DAYS', 7),   // X
        'received_no_response_days' => env('LEGAL_RECEIVED_NO_RESPONSE_DAYS', 14), // Y
    ],
    
    // (yang sudah kita buat kemarin)
    'somasi' => [
        'deadline_days'       => env('LEGAL_SOMASI_DEADLINE_DAYS', 7),
        'remind_days_before'  => env('LEGAL_SOMASI_REMIND_DAYS_BEFORE', 1),
        'remind_hour'         => env('LEGAL_SOMASI_REMIND_HOUR', 9),
        'remind_minute'       => env('LEGAL_SOMASI_REMIND_MINUTE', 0),
    ],
    
    // ✅ mapping eskalasi default
    'escalation_map' => [
        // jika somasi no response → lanjut ke ini
        'somasi' => env('LEGAL_ESCALATE_AFTER_SOMASI', 'ht_execution'),
    ],
    
    /**
     * POLICY MATRIX: role -> status yang boleh dituju (to_status)
     * Sesuaikan isi role dengan field users.level kamu.
     */
    'policy_matrix' => [

        /*
        |--------------------------------------------------------------------------
        | ROLE STRATEGIS / PENGAMBIL KEPUTUSAN
        |--------------------------------------------------------------------------
        */

        // Kepala TI / Owner sistem (otoritas tinggi)
        'KTI' => [
            'submitted',
            'in_progress',
            'waiting',
            'completed',
            'failed',
            'cancelled',
        ],

        // Kepala Bagian Operasional
        'KBO' => [
            'in_progress',
            'waiting',
            'completed',
            'failed',
            'cancelled',
        ],

        // Kepala Seksi Operasional
        'KSO' => [
            'in_progress',
            'waiting',
            'completed',
            'failed',
        ],

        // Kepala Seksi Administrasi / Kredit
        'KSA' => [
            'in_progress',
            'waiting',
        ],

        /*
        |--------------------------------------------------------------------------
        | ROLE ADMINISTRATIF & SUPPORT
        |--------------------------------------------------------------------------
        */

        // Admin sistem (entry & submit awal)
        'ADM' => [
            'submitted',
        ],

        // Back Office
        'BO' => [
            'submitted',
            'in_progress',
        ],

        // Staff umum (misal staff remedial non struktural)
        'STAFF' => [
            'submitted',
        ],

        // SDM tidak punya otoritas legal
        'SDM' => [],

        /*
        |--------------------------------------------------------------------------
        | ROLE TEKNIS & NON-LEGAL
        |--------------------------------------------------------------------------
        */

        // TI (support teknis, bukan pengambil keputusan)
        'TI' => [
            'submitted',
            'in_progress',
            'waiting',
        ],

        /*
        |--------------------------------------------------------------------------
        | FRONTLINER (READ ONLY)
        |--------------------------------------------------------------------------
        */

        'CS'  => [],
        'TL'  => [],
        'TLR' => [],


        /*
        |--------------------------------------------------------------------------
        | FALLBACK
        |--------------------------------------------------------------------------
        */
        '*' => [],
    ],

    'policy_matrix_by_action_type' => [

        /**
         * SOMASI:
         * - ADM/BO/STAFF boleh submit (kirim somasi)
         * - TI boleh bantu status proses
         * - Close (completed/failed/cancelled) hanya KSO/KBO/KTI
         */
        'somasi' => [
            'KTI'   => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KBO'   => ['in_progress','waiting','completed','failed','cancelled'],
            'KSO'   => ['in_progress','waiting','completed','failed'],
            'KSA'   => ['in_progress','waiting'],
            'TI'    => ['submitted','in_progress','waiting'],
            'BO'    => ['submitted','in_progress'],
            'ADM'   => ['submitted'],
            'STAFF' => ['submitted'],
            'CS'    => [],
            'TL'    => [],
            'TLR'   => [],
            'TLRO'   => [],
            'TLSO'   => [],
            'TLFE'   => [],
            'TLBE'   => [],
            'TLUM'   => [],
            'SDM'   => [],
            '*'     => [],
        ],

        /**
         * EKSEKUSI HAK TANGGUNGAN (HT):
         * - Lebih "high impact", close minimal KBO/KTI
         * - KSO boleh proses & update (tapi close dibatasi)
         * - ADM hanya input submit awal
         */
        'ht_execution' => [
            'KTI'   => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KBO'   => ['in_progress','waiting','completed','failed','cancelled'],
            'KBL'   => ['in_progress','waiting','completed','failed','cancelled'],
            'KBF'   => ['in_progress','waiting','completed','failed','cancelled'],
            'KSO'   => ['prepared','submitted','in_progress'], // ✅ boleh approve // ⛔ tidak bisa completed/failed
            'KSF'   => ['prepared','submitted','in_progress'], // ✅ boleh approve
            'KSL'   => ['prepared','submitted','in_progress'], // ✅ boleh approve
            'KSR'   => ['prepared','submitted','in_progress'], // ✅ boleh approve
            'TI'    => ['submitted','in_progress','waiting'],
            'BO'    => ['submitted','in_progress'],
            'ADM'   => ['submitted'],
            'STAFF' => ['submitted'],
            'TLRO'   => [],
            'TLSO'   => [],
            'TLFE'   => [],
            'TLBE'   => [],
            'TLUM'   => [],
            'SDM'   => [],
            '*'     => [],
        ],

        /**
         * EKSEKUSI FIDUSIA:
         * - Close minimal KSO/KBO/KTI (lebih sensitif karena potensi konflik)
         */
        'fidusia_execution' => [
            'KTI'   => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KBO'   => ['in_progress','waiting','completed','failed','cancelled'],
            'KSO'   => ['in_progress','waiting','completed','failed'],
            'KSA'   => ['in_progress','waiting'],
            'TI'    => ['submitted','in_progress','waiting'],
            'BO'    => ['submitted','in_progress'],
            'ADM'   => ['submitted'],
            'STAFF' => ['submitted'],
            'CS'    => [],
            'TL'    => [],
            'TLR'   => [],
            'TLRO'   => [],
            'TLSO'   => [],
            'TLFE'   => [],
            'TLBE'   => [],
            'TLUM'   => [],
            'SDM'   => [],
            '*'     => [],
        ],

        /**
         * GUGATAN PERDATA:
         * - Biasanya proses panjang, status update dipegang struktural
         * - BO/ADM cukup submit dokumen awal
         * - Close (completed/failed/cancelled) hanya KBO/KTI
         */
        'civil_lawsuit' => [
            'KTI'   => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KBO'   => ['in_progress','waiting','completed','failed','cancelled'],
            'KSO'   => ['in_progress','waiting'], // ⛔ tidak bisa close
            'KSA'   => ['in_progress','waiting'],
            'TI'    => ['submitted','in_progress','waiting'],
            'BO'    => ['submitted'], // ⛔ tidak boleh in_progress
            'ADM'   => ['submitted'],
            'STAFF' => [],
            'CS'    => [],
            'TL'    => [],
            'TLR'   => [],
            'TLRO'   => [],
            'TLSO'   => [],
            'TLFE'   => [],
            'TLBE'   => [],
            'TLUM'   => [],
            'SDM'   => [],
            '*'     => [],
        ],

        /**
         * LAPORAN PIDANA:
         * - Paling sensitif: hanya KTI/KBO yang boleh ubah status
         * - Role lain read-only (atau hanya submitted jika kamu butuh entry)
         */
        'criminal_report' => [
            'KTI'   => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KBO'   => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KSO'   => [], // ⛔
            'KSA'   => [],
            'TI'    => [],
            'BO'    => [],
            'ADM'   => [],
            'STAFF' => [],
            'CS'    => [],
            'TL'    => [],
            'TLR'   => [],
            'TLRO'   => [],
            'TLSO'   => [],
            'TLFE'   => [],
            'TLBE'   => [],
            'TLUM'   => [],
            'SDM'   => [],
            '*'     => [],
        ],

        /**
         * PKPU / PAILIT:
         * - Sangat strategis: hanya KTI/KBO
         */
        'pkpu_bankruptcy' => [
            'KTI'   => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KBO'   => ['submitted','in_progress','waiting','completed','failed','cancelled'],
            'KSO'   => [],
            'KSA'   => [],
            'TI'    => [],
            'BO'    => [],
            'ADM'   => [],
            'STAFF' => [],
            'CS'    => [],
            'TL'    => [],
            'TLR'   => [],
            'TLRO'   => [],
            'TLSO'   => [],
            'TLFE'   => [],
            'TLBE'   => [],
            'TLUM'   => [],
            'SDM'   => [],
            '*'     => [],
        ],
    ],

];
