<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Provider-neutral HMAC test/adapter protocol; NOT a real bank/provider driver.
 * Verifies the transport envelope and stores whitelisted observations only.
 * It does not create payments, disburse refunds or verify bank settlement.
 */
final class SignedFinanceEventInbox
{
    public function ingest(Request $request): array
    {
        $enabled = config('finance_gateway.custom_hmac_v1.enabled', false);
        $secret = config('finance_gateway.custom_hmac_v1.secret');
        if (! $enabled || ! is_string($secret) || strlen($secret) < 32) {
            throw new HttpException(503, 'Finance event adapter is not configured.');
        }

        $raw = $request->getContent();
        if (strlen($raw) > 8192) {
            throw new HttpException(413, 'Finance event exceeds size limit.');
        }
        $timestamp = $request->header('X-Finance-Timestamp', '');
        $signature = $request->header('X-Finance-Signature', '');
        if (! is_string($timestamp) || ! preg_match('/^[0-9]{10}$/', $timestamp)
            || ! is_string($signature) || ! preg_match('/^sha256=[a-f0-9]{64}$/', $signature)) {
            throw new HttpException(401, 'Missing or invalid finance event signature.');
        }
        if (abs(now()->timestamp - (int) $timestamp)
            > (int) config('finance_gateway.custom_hmac_v1.max_clock_skew_seconds', 300)) {
            throw new HttpException(401, 'Finance event timestamp is outside allowed window.');
        }
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        if (! hash_equals($expected, $signature)) {
            throw new HttpException(401, 'Invalid finance event signature.');
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new HttpException(422, 'Expected a finance event JSON object.');
        }
        if (array_keys($decoded) !== ['eventId','hotelId','eventType','reference','amountMinor','currency','occurredAt']) {
            throw new HttpException(422, 'Unexpected finance event fields.');
        }

        $eventId = $decoded['eventId'];
        $hotelId = $decoded['hotelId'];
        $eventType = $decoded['eventType'];
        $reference = $decoded['reference'];
        $amount = $decoded['amountMinor'];
        $currency = $decoded['currency'];
        $occurredAt = $decoded['occurredAt'];

        if (! is_string($eventId) || ! preg_match('/^[A-Za-z0-9_.:-]{8,160}$/D', $eventId)
            || ! is_int($hotelId) || $hotelId < 1
            || ! in_array($eventType, ['payment.observed','refund.observed'], true)
            || ! is_string($reference) || ! preg_match('/^[A-Za-z0-9_.:-]{3,120}$/D', $reference)
            || ! is_int($amount) || $amount <= 0 || $amount > 100000000000
            || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)
            || ! is_string($occurredAt) || strlen($occurredAt) > 40
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $occurredAt)
            || ! $this->validTime($occurredAt)) {
            throw new HttpException(422, 'Invalid finance event attributes.');
        }

        if (! DB::table('hotels')->where('id', $hotelId)->exists()) {
            throw new HttpException(422, 'Signed event refers to an unknown hotel.');
        }

        // Canonical JSON contains *only* allowlisted fields; never retain the
        // raw HTTP envelope, secrets, arbitrary metadata, or personal data.
        $canonical = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $digest = hash('sha256', $canonical);

        return DB::transaction(function () use ($eventId, $hotelId, $eventType, $occurredAt, $canonical, $digest): array {
            // Unique provider+event_id enforces deduplication even during a
            // race between two HTTP workers. Never read a missing-row lock.
            $inserted = DB::table('finance_gateway_events')->insertOrIgnore([
                'provider' => 'custom_hmac_v1', 'event_id' => $eventId,
                'hotel_id' => $hotelId,
                'event_type' => $eventType,
                'provider_occurred_at' => (new \DateTimeImmutable($occurredAt))->format('Y-m-d H:i:s'),
                'payload_sha256' => $digest,
                'verification_method' => 'hmac_sha256_timestamped',
                'processing_status' => 'unmapped',
                'payload_json' => $canonical,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $record = DB::table('finance_gateway_events')->where('provider', 'custom_hmac_v1')
                ->where('event_id', $eventId)->first();
            if ($record === null) {
                throw new HttpException(503, 'Finance event could not be persisted.');
            }
            if ((int) $record->hotel_id !== $hotelId || ! hash_equals($record->payload_sha256, $digest)) {
                throw new HttpException(409, 'Event identifier was reused with different content.');
            }
            return ['id' => (string) $record->id, 'replayed' => ! (bool) $inserted,
                'status' => $record->processing_status];
        }, 3);
    }

    private function validTime(string $timestamp): bool
    {
        try {
            new \DateTimeImmutable($timestamp);
            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
