<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Domain\Reservations\ReadinessWriteGuard;
use App\Models\Hotel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupplierBillPostingService
{
    public function record(Hotel $hotel, int $actor, array $input): array
    {
        abort_unless(config('financial_accounting.enable_supplier_billing', false), 409, 'Supplier billing is not enabled.');
        $data = Validator::make($input, [
            'requestKey' => ['required', 'string', 'min:8', 'max:100'],
            'supplierId' => ['required', 'integer', 'min:1'],
            'invoiceReference' => ['required', 'string', 'max:120', 'regex:/\\S/u'],
            'description' => ['required', 'string', 'max:255', 'regex:/\\S/u'],
            'department' => ['required', 'in:rooms,maintenance,administration,utilities,food_beverage'],
            'category' => ['required', 'string', 'max:64', 'regex:/\\S/u'],
            'invoiceDate' => ['required', 'date_format:Y-m-d'],
            'serviceDate' => ['required', 'date_format:Y-m-d'],
            'dueDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:invoiceDate'],
            'amountMinor' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ])->validate();

        return DB::transaction(function () use ($hotel, $actor, $data): array {
            app(ReadinessWriteGuard::class)->lockHotel($hotel);
            abort_unless(DB::table('hotel_memberships')->where('hotel_id', $hotel->id)
                ->where('user_id', $actor)->where('role', 'admin')->where('is_active', true)->exists()
                && DB::table('users')->where('id', $actor)->where('is_active', true)->exists(), 403);
            abort_unless(($hotel->currency ?? 'PHP') === 'PHP', 409, 'Only PHP supplier billing is supported.');
            $reference = trim(preg_replace('/\\s+/u', ' ', $data['invoiceReference']));
            $normalized = mb_strtolower($reference, 'UTF-8');
            $facts = [
                'hotel_id' => (int) $hotel->id, 'supplier_id' => (int) $data['supplierId'],
                'recorded_by' => $actor, 'request_key' => $data['requestKey'],
                'invoice_reference' => $reference, 'normalized_invoice_reference' => $normalized,
                'description' => trim($data['description']), 'department' => $data['department'],
                'category' => trim($data['category']), 'invoice_date' => $data['invoiceDate'],
                'service_date' => $data['serviceDate'], 'due_date' => $data['dueDate'],
                'currency' => 'PHP', 'amount_minor' => (int) $data['amountMinor'],
            ];
            $base = DB::table('supplier_bills')->where('hotel_id', $hotel->id);
            $existing = (clone $base)->where('request_key', $data['requestKey'])->first();
            if ($existing !== null) {
                foreach ($facts as $key => $value) {
                    abort_unless((string) $existing->$key === (string) $value, 409, 'Invoice request key already used with different details.');
                }
                return ['billId' => (string) $existing->id, 'journalId' => (string) $existing->journal_id, 'replayed' => true];
            }
            $supplier = DB::table('hotel_suppliers')->where('hotel_id', $hotel->id)
                ->where('id', $facts['supplier_id'])->lockForUpdate()->first();
            abort_unless($supplier !== null, 404);
            abort_unless((bool) $supplier->is_active, 409, 'Supplier is inactive.');
            abort_if((clone $base)->where('supplier_id', $supplier->id)
                ->where('normalized_invoice_reference', $normalized)->exists(), 409, 'This supplier invoice has already been recorded.');
            $clock = DB::table('financial_business_clocks')->where('hotel_id', $hotel->id)->lockForUpdate()->first();
            abort_unless($clock !== null && $clock->status === 'open', 409, 'An open financial business date is required.');
            abort_if($facts['invoice_date'] > (string) $clock->business_date || $facts['service_date'] > (string) $clock->business_date,
                422, 'Future invoices or services cannot be recognized as incurred expenses.');
            $amount = $facts['amount_minor'];
            // Initial scope: incurred operating expenses only. No input VAT, inventory,
            // capital assets, prepaid expenses or payment recognition in this transaction.
            $journal = app(FinancialJournalPostingService::class)->post(
                (int) $hotel->id, 'supplier-bill:'.$data['requestKey'], 'supplier_bill',
                $data['requestKey'], 'Supplier operating expense invoice', [
                    ['account_code' => 'operating_expense', 'debit_minor' => $amount, 'credit_minor' => 0],
                    ['account_code' => 'accounts_payable', 'debit_minor' => 0, 'credit_minor' => $amount],
                ], $actor
            );
            $date = DB::table('financial_posting_journals')->where('hotel_id', $hotel->id)->where('id', $journal)->value('business_date');
            $bill = DB::table('supplier_bills')->insertGetId($facts + [
                'supplier_name_snapshot' => $supplier->name, 'journal_id' => $journal,
                'business_date' => $date, 'created_at' => now(),
            ]);
            return ['billId' => (string) $bill, 'journalId' => (string) $journal, 'replayed' => false];
        }, 3);
    }
}
