<?php

namespace App\Livewire\LegalAction;

use App\Models\LegalAction;
use App\Models\LegalActionShipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use App\Models\LegalDocument; // sesuaikan model dokumenmu
use Illuminate\Support\Str;
use App\Services\Legal\SomasiTimelineService;
use Illuminate\Support\Facades\Log;
use App\Services\Legal\BottleneckService;




class ShippingForm extends Component
{
    use WithFileUploads;

    public LegalAction $action;

    public ?string $bottleneckLevel = null;
    public ?string $bottleneckLabel = null;

    public string $delivery_channel = '';
    public string $expedition_name  = '';
    public string $receipt_no       = '';
    public string $notes            = '';

    public $receipt_file = null;            // temp upload livewire
    public ?string $receipt_path = null;    // final path (relative to public/)
    public ?string $receipt_original = null;

    public bool $saved = false;
    public bool $receipt_uploaded = false;

    public ?string $shipping_notes = null;
    public bool $locked = false;

    private function guardLocked(): void
    {
        if ($this->locked) {
            abort(403, 'Somasi sudah ditutup. Form pengiriman dikunci.');
        }
    }

    public function mount(LegalAction $action, bool $locked = false): void
    {
        $this->action = $action;
        $this->locked = $locked;

        $this->action->loadMissing('shipment');

        $this->fillFromShipment();

        // ✅ Bottleneck detect
        $this->bottleneckLevel = \App\Services\Legal\BottleneckService::detect($this->action);
        $this->bottleneckLabel = \App\Services\Legal\BottleneckService::label($this->bottleneckLevel);
    }

    public function refreshBottleneck(): void
    {
        $this->action->refresh();
        $this->bottleneckLevel = BottleneckService::detect($this->action);
        $this->bottleneckLabel = BottleneckService::label($this->bottleneckLevel);
    }


    private function fillFromShipment(): void
    {
        $ship = $this->action->shipment;

        if (!$ship) {
            // default stabil
            $this->receipt_path = null;
            $this->receipt_original = null;
            $this->receipt_uploaded = false;
            return;
        }

        $this->delivery_channel = (string) ($ship->delivery_channel ?? '');
        $this->expedition_name  = (string) ($ship->expedition_name ?? '');
        $this->receipt_no       = (string) ($ship->receipt_no ?? '');
        $this->notes            = (string) ($ship->notes ?? '');

        $this->receipt_path     = $ship->receipt_path;
        $this->receipt_original = $ship->receipt_original;
        $this->receipt_uploaded = !empty($ship->receipt_path);
    }

    private function refreshFromDb(): void
    {
        $this->action = $this->action->fresh(['shipment']) ?? $this->action;
        $this->fillFromShipment();
    }

    /**
     * Rules dinamis sesuai channel.
     */
    public function rules(): array
    {
        $base = [
            'delivery_channel' => ['required', 'string', Rule::in(['pos','petugas_bank','kuasa_hukum','lainnya','ao'])],
            'notes'            => ['nullable', 'string', 'max:4000'],
            'shipping_notes'   => ['nullable', 'string', 'max:2000'],
            'receipt_file'     => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ];

        if ($this->delivery_channel === 'pos') {
            $base['expedition_name'] = ['required', 'string', 'max:255'];
            $base['receipt_no']      = ['required', 'string', 'max:100'];
        } else {
            $base['expedition_name'] = ['nullable', 'string', 'max:255'];
            $base['receipt_no']      = ['nullable', 'string', 'max:100'];
        }

        return $base;
    }

    /**
     * Upload bukti pengiriman (tanpa symlink) => simpan ke public/ lewat disk public_direct,
     * lalu update shipment agar state tidak hilang saat re-render.
     */

    public function uploadReceipt(): void
    {
        $this->guardLocked();
        // reset error lama biar UI bersih
        $this->resetErrorBag('receipt_file');

        // 0) Pastikan user memilih file
        if (!$this->receipt_file) {
            $this->addError('receipt_file', 'Silakan pilih file bukti pengiriman terlebih dahulu.');
            return;
        }

        // 1) Validasi file
        $this->validate([
            'receipt_file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        // 2) Simpan file ke public_direct (public/uploads/..)
        $dir  = 'uploads/legal/shipping/' . $this->action->id;
        $path = $this->receipt_file->store($dir, 'public_direct'); // relative path

        $original = $this->receipt_file->getClientOriginalName();
        $mime     = $this->receipt_file->getMimeType();
        $size     = $this->receipt_file->getSize();

        // hash sha256 dari file yang sudah tersimpan
        $hash = null;
        try {
            $absolute = Storage::disk('public_direct')->path($path);
            $hash = is_file($absolute) ? hash_file('sha256', $absolute) : null;
        } catch (\Throwable $e) {
            $hash = null;
        }

        DB::transaction(function () use ($path, $original, $mime, $size, $hash) {

            // ambil shipment existing agar tidak overwrite field lain jadi kosong
            $existing = LegalActionShipment::where('legal_action_id', $this->action->id)->first();

            // ✅ OPTIONAL: kalau sebelumnya ada receipt_path lama, hapus fisiknya SETELAH kita dapat $existing
            // (supaya yang dihapus adalah file lama yg benar)
            if (!empty($existing?->receipt_path) && $existing->receipt_path !== $path) {
                $this->deletePublicFile($existing->receipt_path);
            }

            // A) Update/insert shipment (source of truth Step Pengiriman)
            LegalActionShipment::updateOrCreate(
                ['legal_action_id' => $this->action->id],
                [
                    // jangan paksa overwrite jadi kosong
                    'delivery_channel' => filled($this->delivery_channel) ? $this->delivery_channel : ($existing->delivery_channel ?? null),
                    'expedition_name'  => filled($this->expedition_name)  ? $this->expedition_name  : ($existing->expedition_name  ?? null),
                    'receipt_no'       => filled($this->receipt_no)       ? $this->receipt_no       : ($existing->receipt_no       ?? null),
                    'notes'            => filled($this->notes)            ? $this->notes            : ($existing->notes            ?? null),

                    'receipt_path'     => $path,
                    'receipt_original' => $original,
                    'uploaded_by'      => auth()->id(),
                    'uploaded_at'      => now(),
                ]
            );

            // B) Catat juga ke legal_documents (agar muncul di tab Dokumen)
            // ✅ gunakan konstanta model biar konsisten
            $docType = LegalDocument::DOC_SOMASI_SHIPPING_RECEIPT;

            // OPTIONAL: kalau kamu mau title bisa dinamis dari expedition/receipt no
            $title = 'Bukti Kirim Somasi (Resi)';

            // kalau sebelumnya ada dokumen receipt, kita replace (update)
            $doc = LegalDocument::where('legal_action_id', $this->action->id)
                ->where('doc_type', $docType)
                ->first();

            // OPTIONAL: kalau doc sudah ada & file_path-nya berbeda -> hapus file lama fisiknya
            if ($doc && !empty($doc->file_path) && $doc->file_path !== $path) {
                // file receipt somasi disimpan ke public_direct (uploads/..)
                // jadi aman hapus via deletePublicFile
                if (str_starts_with(ltrim($doc->file_path, '/'), 'uploads/')) {
                    $this->deletePublicFile($doc->file_path);
                }
            }

            LegalDocument::updateOrCreate(
                [
                    'legal_action_id' => $this->action->id,
                    'doc_type'        => $docType,
                ],
                [
                    'legal_case_id' => $this->action->legal_case_id,
                    'title'         => $title,

                    'file_path'   => $path,
                    'file_name'   => $original,
                    'mime_type'   => $mime,
                    'file_size'   => $size,
                    'hash_sha256' => $hash,

                    'uploaded_by' => auth()->id(),
                    'uploaded_at' => now(),
                ]
            );
        });

        // 3) reset input file supaya bisa upload ulang file yang sama
        $this->reset('receipt_file');

        // 4) refresh state dari DB (biar UI tidak balik kosong)
        $this->refreshFromDb();

        $this->syncSomasiMetaFromShipping();

        $this->receipt_uploaded = true;

        $this->dispatch('toast', type: 'success', message: 'Bukti pengiriman berhasil diupload & tercatat di Dokumen.');
    }

    public function removeReceipt(): void
    {
       $this->guardLocked();
       
        $this->saved = false;

        if (!$this->receipt_path) {
            return;
        }

        $this->deletePublicFile($this->receipt_path);

        DB::transaction(function () {
            LegalActionShipment::where('legal_action_id', $this->action->id)->update([
                'receipt_path'     => null,
                'receipt_original' => null,
                'uploaded_by'      => null,
                'uploaded_at'      => null,
            ]);
        });

        $this->refreshFromDb();
        $this->syncSomasiMetaFromShipping();

        $this->dispatch('toast', type: 'info', message: 'Bukti pengiriman dihapus.');
    }

    public function saveShipping(SomasiTimelineService $timeline): void
    {
        Log::info('[SOMASI][SHIPPING] saveShipping HIT', [
            'component'        => static::class,
            'action_id'        => $this->action?->id,
            'legal_case_id'    => $this->action?->legal_case_id,
            'delivery_channel' => $this->delivery_channel,
            'receipt_path'     => $this->receipt_path ?? null,
            'user_id'          => auth()->id(),
            'url'              => request()->fullUrl(),
            'time'             => now()->toDateTimeString(),
        ]);

        $this->syncSomasiMetaFromShipping();

        $this->guardLocked();
        $this->saved = false;

        // validasi dinamis
        $this->validate();

        if (!$this->canSave) {
            $this->addError('delivery_channel', 'Data pengiriman belum lengkap.');
            return;
        }

        if ($this->delivery_channel === 'pos' && !$this->receipt_path) {
            $this->addError('receipt_file', 'Upload bukti pengiriman dulu.');
            return;
        }

        $desc = "LEGAL SOMASI: DIKIRIM\n"
            . "Channel: {$this->delivery_channel}\n"
            . ($this->shipping_notes ? "Catatan: {$this->shipping_notes}" : '');

        // ✅ karena file kamu ada di public/uploads/..., maka pakai asset(path)
        $proofUrl = $this->receipt_path ? asset($this->receipt_path) : null;

        DB::transaction(function () use ($timeline, $desc, $proofUrl) {

            LegalActionShipment::updateOrCreate(
                ['legal_action_id' => $this->action->id],
                [
                    'delivery_channel' => $this->delivery_channel,
                    'expedition_name'  => $this->delivery_channel === 'pos' ? $this->expedition_name : null,
                    'receipt_no'       => $this->delivery_channel === 'pos' ? $this->receipt_no : null,

                    'notes'            => $this->notes,
                    'shipping_notes'   => $this->shipping_notes,

                    'receipt_path'     => $this->receipt_path,
                    'receipt_original' => $this->receipt_original,

                    'uploaded_by'      => $this->receipt_path ? auth()->id() : null,
                    'uploaded_at'      => $this->receipt_path ? now() : null,
                ]
            );

            $timeline->upsert(
                action: $this->action,
                milestone: 'sent',
                result: 'SENT',
                description: $desc,
                proofUrl: $proofUrl
            );

            // ✅ INI YANG KRUSIAL
            $this->syncSomasiMetaFromShipping();
        });

        $this->refreshFromDb();

        $this->saved = true;
        $this->dispatch('toast', type: 'success', message: 'Pengiriman berhasil disimpan.');
    }

    private function deletePublicFile(string $relativePath): void
    {
        $this->guardLocked();
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') return;

        if (Storage::disk('public_direct')->exists($relativePath)) {
            Storage::disk('public_direct')->delete($relativePath);
        }
    }

    #[Computed]
    public function canSave(): bool
    {
        $channel = trim((string) $this->delivery_channel);
        if ($channel === '') return false;

        // POS wajib: expedition_name + receipt_no + bukti
        if ($channel === 'pos') {
            return trim($this->expedition_name) !== ''
                && trim($this->receipt_no) !== ''
                && !empty($this->receipt_path);
        }

        // Selain POS: cukup pilih channel (bukti optional)
        return true;
    }

    public function render()
    {
        return view('livewire.legal-action.shipping-form');
    }

    private function syncSomasiMetaFromShipping(): void
    {
        // Pastikan action terbaru
        $action = $this->action->fresh() ?? $this->action;

        $meta = is_array($action->meta)
            ? $action->meta
            : (is_string($action->meta) ? json_decode($action->meta, true) : []);

        if (!is_array($meta)) $meta = [];
        $meta['somasi'] = (array) ($meta['somasi'] ?? []);

        // Map field Livewire -> meta yang dibaca Blade/computeProgress
        $meta['somasi']['delivery_method'] = $this->delivery_channel ?: null; // UI ringkasan pakai ini
        $meta['somasi']['courier_name']    = $this->expedition_name ?: null;  // UI ringkasan
        $meta['somasi']['tracking_no']     = $this->receipt_no ?: null;       // UI ringkasan

        // catatan (pilih yg paling “ringkas” untuk ringkasan)
        $meta['somasi']['shipping_note']   = $this->shipping_notes ?: ($this->notes ?: null);

        // simpan info resi file (opsional, biar UI bisa tampilkan ada bukti)
        $meta['somasi']['shipping_receipt_path']     = $this->receipt_path ?: null;
        $meta['somasi']['shipping_receipt_original'] = $this->receipt_original ?: null;

        $action->meta = $meta;
        $action->save();

        // sync ke property component
        $this->action = $action;
    }

}
