<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Logging\SecurityLog;
use App\Services\PlatformSubscriptionPayments as Payments;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformSubscriptionPaymentController
{
    public function index(Request $request, int $invoice): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);
        $bill = Payments::invoices()->where('invoice.id', $invoice)->first();
        abort_unless($bill, 404);
        $page = DB::table('platform_subscription_payments')->where('invoice_id', $invoice)->orderByDesc('id')->paginate(20);
        return $this->response(['data' => $page->getCollection()->map(fn ($row) => Payments::project($row))->values(),
            'balance' => ['amountMinor' => (int) $bill->amount_minor, 'verifiedMinor' => (int) $bill->verified_minor,
                'pendingMinor' => (int) $bill->pending_minor, 'outstandingMinor' => max(0, (int) $bill->amount_minor - (int) $bill->verified_minor)],
            'meta' => ['page' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function store(Request $request, int $invoice): JsonResponse
    {
        $input = $request->validate([
            'method' => ['required', 'in:gcash,maya,bank_transfer,cash'],
            'reference' => ['required', 'string', 'min:3', 'max:120', 'regex:/\A[A-Za-z0-9][A-Za-z0-9 ._\/-]*\z/D'],
            'evidenceReference' => ['required', 'string', 'min:3', 'max:200'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:999999999'],
            'receivedOn' => ['required', 'date_format:Y-m-d'],
            'confirmed' => ['accepted'],
        ]);
        $reference = strtoupper(preg_replace('/\s+/', '', trim($input['reference'])));
        $key = hash('sha256', $input['method'].':'.$reference);
        $replay = function (object $row) use ($invoice, $input): array {
            abort_unless((int) $row->invoice_id === $invoice && (int) $row->amount_minor === (int) $input['amountMinor']
                && substr((string) $row->received_on, 0, 10) === $input['receivedOn']
                && $row->evidence_reference === trim($input['evidenceReference']), 409, 'Payment reference has already been recorded. Review the existing record.');
            return [$row, false];
        };
        try {
            [$row, $created] = DB::transaction(function () use ($request, $invoice, $input, $reference, $key, $replay): array {
                $bill = DB::table('platform_subscription_invoices')->where('id', $invoice)->lockForUpdate()->first();
                abort_unless($bill, 404);
                $existing = DB::table('platform_subscription_payments')->where('reference_key', $key)->first();
                if ($existing) {
                    return $replay($existing);
                }
                $today = CarbonImmutable::today('Asia/Manila')->toDateString();
                if ($input['receivedOn'] > $today || $input['receivedOn'] < substr((string) $bill->issued_on, 0, 10)) {
                    throw ValidationException::withMessages(['receivedOn' => ['Choose a received date from issuance through today.']]);
                }
                $remaining = (int) $bill->amount_minor - Payments::paid($invoice) - Payments::pending($invoice);
                if ((int) $input['amountMinor'] > $remaining) {
                    throw ValidationException::withMessages(['amountMinor' => ['Amount exceeds remaining balance after pending payments.']]);
                }
                $id = DB::table('platform_subscription_payments')->insertGetId([
                    'invoice_id' => $invoice, 'method' => $input['method'], 'reference' => $reference, 'reference_key' => $key,
                    'evidence_reference' => trim($input['evidenceReference']), 'amount_minor' => (int) $input['amountMinor'],
                    'received_on' => $input['receivedOn'], 'status' => 'pending', 'recorded_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                return [DB::table('platform_subscription_payments')->where('id', $id)->first(), true];
            }, 3);
        } catch (QueryException $error) {
            $existing = DB::table('platform_subscription_payments')->where('reference_key', $key)->first();
            if (! $existing) {
                throw $error;
            }
            [$row, $created] = $replay($existing);
        }
        if ($created) {
            SecurityLog::record('pms.security.platform.subscription_payment_recorded', ['actor_id' => (int) $request->user()->id]);
        }
        return $this->response(['data' => Payments::project($row), 'replayed' => ! $created], $created ? 201 : 200);
    }

    public function review(Request $request, int $invoice, int $payment): JsonResponse
    {
        $input = $request->validate([
            'decision' => ['required', 'in:verified,rejected'],
            'note' => ['required', 'string', 'min:10', 'max:500'],
            'confirmed' => ['accepted'],
            'selfReviewConfirmed' => ['sometimes', 'boolean'],
        ]);
        $actor = (int) $request->user()->id;
        $row = DB::transaction(function () use ($input, $invoice, $payment, $actor): object {
            $bill = DB::table('platform_subscription_invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_unless($bill, 404);
            $row = DB::table('platform_subscription_payments')->where('invoice_id', $invoice)->where('id', $payment)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->status !== 'pending') {
                abort_unless($row->status === $input['decision'] && (int) $row->reviewed_by === $actor
                    && $row->review_note === trim($input['note']), 409, 'Payment has already been reviewed.');
                return $row;
            }
            $self = $row->recorded_by !== null && (int) $row->recorded_by === $actor;
            if ($self && ! ($input['selfReviewConfirmed'] ?? false)) {
                throw ValidationException::withMessages(['selfReviewConfirmed' => ['Acknowledge self-review or ask another platform administrator to review.']]);
            }
            if ($input['decision'] === 'verified' && Payments::paid($invoice) + (int) $row->amount_minor > (int) $bill->amount_minor) {
                throw ValidationException::withMessages(['decision' => ['Verification would exceed the billing record amount.']]);
            }
            DB::table('platform_subscription_payments')->where('id', $payment)->update([
                'status' => $input['decision'], 'reviewed_by' => $actor, 'review_note' => trim($input['note']),
                'reviewed_at' => now(), 'self_reviewed' => $self, 'updated_at' => now(),
            ]);
            return DB::table('platform_subscription_payments')->where('id', $payment)->first();
        }, 3);
        SecurityLog::record('pms.security.platform.subscription_payment_reviewed', ['actor_id' => $actor]);
        return $this->response(['data' => Payments::project($row)]);
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }
}
