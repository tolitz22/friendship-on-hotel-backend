<?php

use App\Services\PushAlertPayload;

it('routes financial alerts without exposing financial values', function (): void {
    $payment = PushAlertPayload::make(5, ['id' => 'payment_pending_review:10', 'kind' => 'payment_pending_review', 'target' => 'billing', 'bookingId' => '6', 'paymentId' => '10', 'reference' => 'SECRET', 'amount' => 9000]);
    expect($payment['url'])->toBe('/billing?hotel=5&booking=6&payment=10');
    expect(json_encode($payment))->not->toContain('SECRET', '9000');
    expect(PushAlertPayload::make(5, ['id' => 'expense_recorded:7', 'kind' => 'expense_recorded', 'target' => 'expenses', 'expenseId' => '7'])['url'])->toBe('/expenses?hotel=5&expense=7');
    expect(PushAlertPayload::make(5, ['id' => 'cashier_discrepancy:8', 'kind' => 'cashier_discrepancy', 'target' => 'cashier-shifts', 'shiftId' => '8'])['url'])->toBe('/cashier-shifts?hotel=5&shift=8');
});
