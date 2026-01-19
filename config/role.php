<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Role Grouping Aplikasi
    |--------------------------------------------------------------------------
    | File ini digunakan untuk mengelompokkan level user
    | agar logic aplikasi tidak hardcode ke satu nilai level.
    | Layak audit & mudah dikembangkan.
    */

    'kasi_levels' => [
        'ksl', // Kasi Legal
        'ksf', // Kasi Funding
        'kso', // Kasi Operasional
        'ksr', // Kasi Remedial
        'ksa', // Kasi Administrasi
        
    ],

    'kabag_levels' => [
        'kbl', // Kabag Legal
        'kbf', // Kabag Funding
        'kbo', // Kabag Operasional
        'kti', // Kabag TI
        
    ],

];
