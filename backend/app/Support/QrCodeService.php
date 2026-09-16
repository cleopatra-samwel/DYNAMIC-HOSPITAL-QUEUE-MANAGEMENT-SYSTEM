<?php

namespace App\Support;

use App\Models\Visit;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Phase 8 — encodes the existing public tracking URL (never the numeric
 * visit id) into a QR code the patient can scan instead of typing the
 * link. Uses endroid/qr-code rather than hand-rolling QR encoding, per
 * the spec's explicit instruction not to reinvent that.
 */
class QrCodeService
{
    public static function trackingUrl(Visit $visit): string
    {
        return rtrim(config('app.frontend_url', config('app.url')), '/')."/track/{$visit->tracking_token}";
    }

    /** Base64 PNG data URI, ready to drop straight into an <img src="..."> tag. */
    public static function trackingQrDataUri(Visit $visit): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            data: self::trackingUrl($visit),
            size: 300,
            margin: 10,
        ))->build();

        return $result->getDataUri();
    }

    /**
     * The self check-in page — a STATIC url, the same for every patient
     * (posted once at the entrance), unlike trackingUrl() above which is
     * unique per visit. See CheckInPage.jsx/routes/AppRoutes.jsx's public
     * /check-in route.
     */
    public static function checkInUrl(): string
    {
        return rtrim(config('app.frontend_url', config('app.url')), '/').'/check-in';
    }

    /** Raw PNG bytes — for saving to a file (see qr:generate-check-in), not embedding inline. */
    public static function checkInQrPngBytes(): string
    {
        $result = (new Builder(
            writer: new PngWriter(),
            data: self::checkInUrl(),
            size: 400,
            margin: 16,
        ))->build();

        return $result->getString();
    }
}
