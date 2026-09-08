<?php

namespace App\Domain\Sales\Support;

final class SatInvoiceFileCodec
{
    public static function toBase64(?string $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^data:[^;]+;base64,(.+)$/s', $trimmed, $match) === 1) {
            $trimmed = (string) $match[1];
        }

        if (self::looksLikeDecodedFile($trimmed)) {
            return base64_encode($trimmed);
        }

        $compact = preg_replace('/\s+/', '', $trimmed) ?? $trimmed;
        $decoded = base64_decode($compact, true);
        if (is_string($decoded) && $decoded !== '') {
            return base64_encode($decoded);
        }

        return base64_encode($trimmed);
    }

    public static function decode(?string $base64): ?string
    {
        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        $decoded = base64_decode($base64, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    public static function looksLikePdf(string $data): bool
    {
        return str_starts_with(ltrim($data), '%PDF');
    }

    public static function looksLikeXml(string $data): bool
    {
        $start = ltrim($data);

        return str_starts_with($start, '<?xml')
            || str_starts_with($start, '<cfdi:')
            || str_starts_with($start, '<Comprobante');
    }

    public static function looksLikeDecodedFile(string $data): bool
    {
        return self::looksLikePdf($data) || self::looksLikeXml($data);
    }
}
