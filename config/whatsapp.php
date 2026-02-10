<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Global Switch
    |--------------------------------------------------------------------------
    | Master ON/OFF WhatsApp notification
    */
    'enabled' => env('WA_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    | log    : hanya log ke laravel.log (DEV / testing)
    | qontak : kirim via Qontak WhatsApp API
    */
    'driver'  => env('WA_DRIVER', 'log'), // log | qontak


    /*
    |--------------------------------------------------------------------------
    | Default Templates
    |--------------------------------------------------------------------------
    | Semua modul (SmartKPI, SmartCRMS, SHM, dll) default pakai
    | template universal 5 variable
    */
    'defaults' => [
        'language'          => 'id',

        // template universal (5 variable)
        'ticket_template'   => 'ticket_notify_any',
        'task_template'     => 'ticket_notify_any',
        'routine_template'  => 'ticket_notify_any',
        'daily_template'    => 'ticket_notify_any',
    ],


    /*
    |--------------------------------------------------------------------------
    | Recipient Groups
    |--------------------------------------------------------------------------
    | Group ID / Contact ID di Qontak
    | (opsional – kalau kosong akan fallback ke kirim per-user)
    */
    'recipients' => [

        // === existing SmartKPI ===
        'ti_group'      => env('WA_TI_GROUP', ''),
        'kti_group'     => env('WA_KTI_GROUP', ''),

        // === SHM Check ===
        // Group WA KSA / KBO / SAD
        // Kalau kosong → kirim ke user KSA/SAD satu-satu
        'shm_sad_group' => env('WA_SHM_SAD_GROUP', ''),
    ],


    /*
    |--------------------------------------------------------------------------
    | Qontak Configuration
    |--------------------------------------------------------------------------
    */
    'qontak' => [
        'base_url'   => env('QONTAK_BASE', ''),
        'api_token'  => env('QONTAK_TOKEN', ''),
        'channel_id' => env('QONTAK_CHANNEL_ID', null),

        // ✅ INI WAJIB
        'endpoint_send_template' => env(
            'QONTAK_ENDPOINT_SEND_TEMPLATE',
            'whatsapp/send-template'
        ),

        'templates' => [
            'ticket_notify_any' => env('QONTAK_TMP_TICKET_NOTIFY'),
            'broadcast'         => env('QONTAK_TMP_BROADCAST'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Greetings (Opsional)
    |--------------------------------------------------------------------------
    | Bisa dipakai kalau template menyertakan sapaan
    */
    'greetings' => [
        'kti' => 'Pak Kabag TI',
        'ksa' => 'Bu Kasi Admin Kredit',
        'sad' => 'Admin Kredit',
    ],


    /*
    |--------------------------------------------------------------------------
    | Notification Behavior Flags
    |--------------------------------------------------------------------------
    */
    'notify' => [

        // Default SmartKPI:
        // false = jangan kirim ke PIC saat status DONE / CLOSED
        'pic_on_done' => false,

        // SHM:
        // true  = kirim WA ke pemohon saat CLOSED (hasil upload)
        // false = tidak kirim
        'shm_notify_on_closed' => false,
    ],

];
