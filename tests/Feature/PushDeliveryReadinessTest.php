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

it('supports every hotel without an explicit hotel ID only when all scope is selected', function (): void {
    $demo = Hotel::create(['name' => 'Client Demo']);
    $real = Hotel::create(['name' => 'All scope QA']);
    $readiness = app(PushDeliveryReadiness::class);
    config(['push.scope' => 'all', 'push.hotel_id' => null, 'push.allow_non_demo' => false, 'push.enabled' => false]);
    expect($readiness->scopeConfigurationIssues())->toBe([]);
    expect($readiness->scopeIssues())->toBe([]);
    expect($readiness->configurationIssues())->not->toContain('hotel_id_invalid');
    expect($readiness->hotels()->pluck('id')->all())->toContain($demo->id, $real->id);
    expect($readiness->hotelAllowed($real))->toBeTrue();
    $third = Hotel::create(['name' => 'New property QA']);
    expect($readiness->hotels()->whereKey($third->id)->exists())->toBeTrue();
    $this->artisan('pms:push-check')->expectsOutput('Hotel scope: all')->doesntExpectOutput('hotel_id_invalid')->assertExitCode(1);
    expect(DB::table('push_deliveries')->count())->toBe(0);
    config(['push.scope' => 'invalid']);
    expect($readiness->scopeIssues())->toBe(['push_scope_invalid']);
    expect($readiness->hotels()->count())->toBe(0);
    config(['push.scope' => 'pilot']);
    expect($readiness->scopeIssues())->toBe(['hotel_id_invalid']);
    config(['push.hotel_id' => $demo->id]);
    expect($readiness->hotels()->pluck('id')->all())->toBe([$demo->id]);
});
