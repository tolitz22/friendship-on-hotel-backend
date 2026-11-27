<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\SecurityLog;
use App\Models\Hotel;
use App\Models\PlatformHotelSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlatformSubscriptionInvoiceController
{
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'in:all,unpaid,overdue'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $today = CarbonImmutable::today('Asia/Manila')->toDateString();
        $query = DB::table('platform_subscription_invoices');
        $search = trim($input['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $query->where(fn ($q) => $q->whereRaw("LOWER(hotel_name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(reference) LIKE ? ESCAPE '!'", [$pattern]));
        }
        // No payment ledger exists yet. All issued balances remain unpaid.
        if (($input['status'] ?? 'all') === 'overdue') {
            $query->whereDate('due_on', '<', $today);
        } elseif (($input['status'] ?? 'all') === 'unpaid') {
            $query->whereDate('due_on', '>=', $today);
        }
        $total = (int) (clone $query)->sum('amount_minor');
        $page = $query->orderByDesc('id')->paginate(20);

        return $this->response(['data' => $page->getCollection()->map(fn ($row) => $this->project($row, $today))->values(),
            'summary' => ['outstandingMinor' => $total, 'currency' => 'PHP'], 'today' => $today,
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function store(Request $request, int $hotel): JsonResponse
    {
        $input = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'dueOn' => ['required', 'date_format:Y-m-d'],
            'confirmed' => ['accepted'],
        ]);
        $today = CarbonImmutable::today('Asia/Manila');
        $result = DB::transaction(function () use ($request, $hotel, $input, $today): array {
            $property = Hotel::query()->lockForUpdate()->findOrFail($hotel);
            $agreement = PlatformHotelSubscription::where('hotel_id', $hotel)->lockForUpdate()->firstOrFail();
            $revision = (int) $input['revision'];
            // Retrying the same agreement and due date returns the same document,
            // even if the agreement has advanced in the meantime.
            $existing = DB::table('platform_subscription_invoices')->where('subscription_id', $agreement->id)
                ->where('agreement_revision', $revision)->first();
            if ($existing) {
                abort_unless(substr((string) $existing->due_on, 0, 10) === $input['dueOn'], 409, 'This agreement revision already has a billing record with a different due date.');

                return [$existing, false];
            }
            abort_unless($agreement->revision === $revision, 409, 'Agreement changed. Refresh before issuing.');
            if ($agreement->kind !== 'paid' || $agreement->price_minor <= 0 || $agreement->currency !== 'PHP') {
                throw ValidationException::withMessages(['revision' => ['Only a positive-price PHP paid agreement can be billed.']]);
            }
            if ($input['dueOn'] < $today->toDateString() || $input['dueOn'] > $today->addDays(90)->toDateString()) {
                throw ValidationException::withMessages(['dueOn' => ['Choose a due date from today through the next 90 days.']]);
            }
            $change = DB::table('platform_hotel_subscription_changes')->where('subscription_id', $agreement->id)
                ->where('revision', $revision)->first();
            abort_unless($change && in_array($change->action, ['assign', 'renew'], true), 409, 'Agreement history is missing or not billable.');
            $snapshot = json_decode($change->snapshot, true, 512, JSON_THROW_ON_ERROR);
            $start = $agreement->starts_on->toDateString();
            $end = $agreement->expires_on->toDateString();
            abort_unless(($snapshot['priceMinor'] ?? null) === $agreement->price_minor
                && ($snapshot['expiresOn'] ?? null) === $end && ($snapshot['startsOn'] ?? null) === $start
                && ($snapshot['planName'] ?? null) === $agreement->plan_name && ($snapshot['cycle'] ?? null) === $agreement->cycle
                && ($snapshot['kind'] ?? null) === 'paid', 409, 'Agreement snapshot does not match the current terms.');
            if ($change->action === 'renew') {
                $previous = DB::table('platform_hotel_subscription_changes')->where('subscription_id', $agreement->id)
                    ->where('revision', $revision - 1)->first();
                abort_unless($previous, 409, 'Prior agreement revision is missing.');
                $prior = json_decode($previous->snapshot, true, 512, JSON_THROW_ON_ERROR);
                abort_unless(isset($prior['expiresOn']) && is_string($prior['expiresOn']), 409, 'Prior agreement expiry is missing.');
                $start = max($start, $prior['expiresOn']);
            }
            abort_unless($start < $end, 409, 'Invalid billing period.');
            $overlap = DB::table('platform_subscription_invoices')->where('hotel_id', $hotel)
                ->whereDate('period_start', '<', $end)->whereDate('period_end', '>', $start)->exists();
            abort_if($overlap, 409, 'A billing record already overlaps this service period.');
            $id = DB::table('platform_subscription_invoices')->insertGetId([
                'hotel_id' => $hotel, 'subscription_id' => $agreement->id, 'agreement_revision' => $revision,
                'reference' => 'SaaS-'.Str::upper(Str::random(20)), 'hotel_name' => $property->name,
                'plan_name' => $agreement->plan_name, 'currency' => 'PHP', 'cycle' => $agreement->cycle,
                'amount_minor' => $agreement->price_minor, 'period_start' => $start, 'period_end' => $end,
                'issued_on' => $today->toDateString(), 'due_on' => $input['dueOn'], 'issued_by' => $request->user()->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [DB::table('platform_subscription_invoices')->where('id', $id)->first(), true];
        }, 3);
        if ($result[1]) {
            SecurityLog::record('pms.security.platform.subscription_invoice_issued', ['actor_id' => (int) $request->user()->id, 'hotel_id' => $hotel]);
        }

        return $this->response(['data' => $this->project($result[0], $today->toDateString()), 'replayed' => ! $result[1]], $result[1] ? 201 : 200);
    }

    private function project(object $row, string $today): array
    {
        $due = substr((string) $row->due_on, 0, 10);
        return ['id' => (string) $row->id, 'hotelId' => (string) $row->hotel_id, 'hotelName' => $row->hotel_name,
            'reference' => $row->reference, 'planName' => $row->plan_name, 'agreementRevision' => (int) $row->agreement_revision,
            'currency' => $row->currency, 'cycle' => $row->cycle, 'amountMinor' => (int) $row->amount_minor,
            'outstandingMinor' => (int) $row->amount_minor, 'status' => $due < $today ? 'overdue' : 'unpaid',
            'periodStart' => substr((string) $row->period_start, 0, 10), 'periodEnd' => substr((string) $row->period_end, 0, 10),
            'issuedOn' => substr((string) $row->issued_on, 0, 10), 'dueOn' => $due, 'issuedBy' => $row->issued_by === null ? null : (string) $row->issued_by];
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
