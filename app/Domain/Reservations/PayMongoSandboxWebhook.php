<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PayMongo TEST-MODE webhook transport adapter. Authenticates with the
 * documented Paymongo-Signature (t,te,li) contract. No financial postings.
 *
 * The signing secret belongs to one explicitly configured sandbox endpoint.
 * Do not reuse this adapter to receive live-mode events.
 */
final class PayMongoSandboxWebhook
{
    public function receive(Request $request): array
    {
        $enabled = config('paymongo_sandbox.enabled', false);
        $secret = config('paymongo_sandbox.webhook_secret');
        $hotelId = filter_var(config('paymongo_sandbox.hotel_id'), FILTER_VALIDATE_INT);
        if (! $enabled || ! is_string($secret) || strlen($secret) < 32
            || ! $hotelId || $hotelId < 1) {
            throw new HttpException(503, 'PayMongo sandbox webhook is not configured.');
        }

        $raw = $request->getContent();
        if (strlen($raw) > 65536) {
            throw new HttpException(413, 'Webhook payload exceeds limit.');
        }
        $header = $request->header('Paymongo-Signature', '');
        if (! is_string($header)) {
            throw new HttpException(401, 'Missing signature.');
        }
        $parts = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2 || ! in_array($pair[0], ['t', 'te', 'li'], true)
                || array_key_exists($pair[0], $parts)) {
                throw new HttpException(401, 'Invalid PayMongo signature header.');
            }
            $parts[$pair[0]] = $pair[1];
        }
        $timestamp = $parts['t'] ?? null;
        $signature = $parts['te'] ?? null;
        if (! is_string($timestamp) || ! preg_match('/^[0-9]{10}$/D', $timestamp)
            || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/D', $signature)
            || ! array_key_exists('li', $parts)) {
            throw new HttpException(401, 'Invalid PayMongo test signature.');
        }
        if (abs(now()->timestamp - (int) $timestamp) >
            (int) config('paymongo_sandbox.max_skew_seconds', 300)) {
            throw new HttpException(401, 'Stale PayMongo webhook.');
        }

        $computed = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        if (! hash_equals($computed, $signature)) {
            throw new HttpException(401, 'PayMongo webhook signature failed.');
        }

        $body = json_decode($raw, true);
        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            throw new HttpException(422, 'Malformed PayMongo event envelope.');
        }
        $event = $body['data'];
        $attrs = $event['attributes'] ?? null;
        $eventId = $event['id'] ?? null;
        $type = is_array($attrs) ? ($attrs['type'] ?? null) : null;

        // Never persist or act on live deliveries received at a sandbox URL.
        if (! is_array($attrs) || ($attrs['livemode'] ?? null) !== false) {
            throw new HttpException(422, 'Only PayMongo test-mode events are accepted.');
        }
        if (! is_string($eventId) || strlen($eventId) < 8 || strlen($eventId) > 160
            || ! preg_match('/^[a-zA-Z0-9_:-]+$/D', $eventId)
            || ! is_string($type) || strlen($type) > 100) {
            throw new HttpException(422, 'Invalid PayMongo event identity.');
        }

        // Store only allowlisted metadata; do not collect the full gateway
        // payload, payment instrument, billing details or customer PII.
        $resource = $attrs['data'] ?? null;
        $resourceId = is_array($resource) ? ($resource['id'] ?? null) : null;
        $resourceId = is_string($resourceId) && strlen($resourceId) <= 160 ? $resourceId : null;
        $ref = is_array($resource) && is_array($resource['attributes'] ?? null)
            ? ($resource['attributes']['reference_number'] ?? null) : null;
        $ref = is_string($ref) && strlen($ref) <= 160 ? $ref : null;

        if (! DB::table('hotels')->where('id', $hotelId)->exists()) {
            throw new HttpException(503, 'Configured sandbox hotel does not exist.');
        }
        $digest = hash('sha256', $raw);

        return DB::transaction(function () use ($hotelId, $eventId, $type, $resourceId, $ref, $digest): array {
            $inserted = DB::table('paymongo_sandbox_events')->insertOrIgnore([
                'hotel_id' => $hotelId,
                'event_id' => $eventId, 'event_type' => $type,
                'payload_sha256' => $digest,
                'resource_id' => $resourceId, 'reference' => $ref,
                'processing_status' => 'unmapped',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $stored = DB::table('paymongo_sandbox_events')->where('event_id', $eventId)->first();
            if ($stored === null) {
                throw new HttpException(503, 'Webhook was not recorded.');
            }
            if ((int) $stored->hotel_id !== $hotelId ||
                ! hash_equals($stored->payload_sha256, $digest)) {
                throw new HttpException(409, 'PayMongo event ID reused with different content.');
            }
            return [
                'id' => (string) $stored->id,
                'status' => $stored->processing_status,
                'replayed' => ! (bool) $inserted,
                'isSettled' => false,
            ];
        }, 3);
    }
}
