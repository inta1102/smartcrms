<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_dekom_snapshots', function (Blueprint $table) {

            $table->id();

            // periode dashboard
            $table->date('period_month')->index();
            $table->date('as_of_date')->nullable();

            // mode builder
            $table->string('mode', 10)->default('eom'); // eom | realtime

            /*
            |--------------------------------------------------------------------------
            | PORTOFOLIO SUMMARY
            |--------------------------------------------------------------------------
            */

            $table->decimal('total_os', 18, 2)->default(0);
            $table->integer('total_noa')->default(0);

            $table->decimal('npl_os', 18, 2)->default(0);
            $table->decimal('npl_pct', 8, 4)->default(0);

            /*
            |--------------------------------------------------------------------------
            | KOLEKTIBILITAS
            |--------------------------------------------------------------------------
            */

            $table->decimal('l_os', 18, 2)->default(0);
            $table->decimal('dpk_os', 18, 2)->default(0);
            $table->decimal('kl_os', 18, 2)->default(0);
            $table->decimal('d_os', 18, 2)->default(0);
            $table->decimal('m_os', 18, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | FT BUCKET
            |--------------------------------------------------------------------------
            */

            $table->decimal('ft0_os', 18, 2)->default(0);
            $table->decimal('ft1_os', 18, 2)->default(0);
            $table->decimal('ft2_os', 18, 2)->default(0);
            $table->decimal('ft3_os', 18, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | RESTRUKTURISASI
            |--------------------------------------------------------------------------
            */

            $table->decimal('restr_os', 18, 2)->default(0);
            $table->integer('restr_noa')->default(0);

            /*
            |--------------------------------------------------------------------------
            | DPD WINDOW
            |--------------------------------------------------------------------------
            */

            $table->decimal('dpd6_os', 18, 2)->default(0);
            $table->decimal('dpd12_os', 18, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | TARGET & REALISASI
            |--------------------------------------------------------------------------
            */

            $table->decimal('target_ytd', 18, 2)->default(0);
            $table->decimal('realisasi_mtd', 18, 2)->default(0);
            $table->decimal('realisasi_ytd', 18, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | META
            |--------------------------------------------------------------------------
            */

            $table->json('meta')->nullable();

            $table->timestamps();

            // unique per periode + mode
            $table->unique(['period_month', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_dekom_snapshots');
    }
};