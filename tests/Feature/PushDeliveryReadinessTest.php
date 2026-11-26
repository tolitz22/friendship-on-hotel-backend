<?php

use App\Models\Hotel;
use App\Services\PushDeliveryReadiness;
use Illuminate\Support\Facades\DB;

it('fails preflight safely for missing settings without creating deliveries', function (): void {
    config(['push.enabled' => false, 'push.hotel_id' => null, 'push.public_key' => null, 'push.private_key' => 'PRIVATE-QA-SENTINEL', 'push.subject' => null]);
    $this->artisan('pms:push-check', ['--scan' => true])
        ->expectsOutput('Delivery switch: disabled')
        ->expectsOutput('private_key_invalid')
        ->doesntExpectOutput('PRIVATE-QA-SENTINEL')
        ->assertExitCode(1);
    expect(DB::table('push_deliveries')->count())->toBe(0);
    $this->artisan('pms:push-deliver')->assertExitCode(0);
});

it('requires explicit non-demo authorization and validates migrated push schema', function (): void {
    $demo = Hotel::create(['name' => 'Client Demo']);
    $real = Hotel::create(['name' => 'Production QA']);
    $readiness = app(PushDeliveryReadiness::class);
    config(['push.allow_non_demo' => false]);
    expect($readiness->hotelAllowed($demo))->toBeTrue();
    expect($readiness->hotelAllowed($real))->toBeFalse();
    expect($readiness->schemaIssues())->toBe([]);
    config(['push.hotel_id' => $real->id, 'push.enabled' => false]);
    $this->artisan('pms:push-check')->expectsOutput('non_demo_hotel_not_authorized')->assertExitCode(1);
    config(['push.allow_non_demo' => true]);
    expect($readiness->hotelAllowed($real))->toBeTrue();
});

it('checks canonical key encoding and subject syntax without exposing key material', function (): void {
    $encode = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    config(['push.public_key' => $encode(chr(4).str_repeat(chr(1), 64)),
        'push.private_key' => $encode(str_repeat(chr(1), 32)), 'push.subject' => 'mailto:qa@example.test', 'push.hotel_id' => '1']);
    $readiness = app(PushDeliveryReadiness::class);
    expect($readiness->configurationIssues())->not->toContain('public_key_invalid', 'private_key_invalid', 'subject_invalid', 'hotel_id_invalid');
    config(['push.public_key' => $encode(chr(3).str_repeat(chr(1), 64)), 'push.private_key' => $encode(str_repeat(chr(0), 32)), 'push.subject' => 'http://example.test']);
    expect($readiness->configurationIssues())->toContain('public_key_invalid', 'private_key_invalid', 'subject_invalid');
});
