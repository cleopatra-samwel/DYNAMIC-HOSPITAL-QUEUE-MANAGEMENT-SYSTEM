<?php

namespace App\Console\Commands;

use App\Support\QrCodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Generates the STATIC QR code for the self check-in page — posted once
 * at the hospital entrance, the same image for every patient (unlike the
 * per-visit tracking QR from Phase 8, which is only ever generated
 * on-the-fly per visit, never saved as a standalone file). Re-runnable:
 * if FRONTEND_URL ever changes (e.g. moving from a dev URL to the real
 * production domain), just run this again to regenerate the same file
 * with the new URL baked in.
 */
class GenerateCheckInQrCode extends Command
{
    protected $signature = 'qr:generate-check-in';

    protected $description = 'Generates the static self check-in QR code PNG (for entrance signage) into storage/app/public/qr-codes.';

    public function handle(): int
    {
        $path = 'qr-codes/check-in.png';

        Storage::disk('public')->put($path, QrCodeService::checkInQrPngBytes());

        $this->info('Check-in QR code generated.');
        $this->line('Encodes: '.QrCodeService::checkInUrl());
        $this->line('Saved to: storage/app/public/'.$path);
        $this->line('View it at: '.Storage::disk('public')->url($path));

        return self::SUCCESS;
    }
}
