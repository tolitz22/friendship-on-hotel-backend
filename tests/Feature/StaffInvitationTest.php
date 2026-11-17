<?php

declare(strict_types=1);

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

function inviteHotelAsAdmin(): Hotel
{
    $hotel=Hotel::create(['name'=>'Invitation Hotel '.uniqid()]);
    $admin=User::create([
        'name'=>'Invitation Admin',
        'email'=>'invite-admin-'.uniqid().'@example.test',
        'password'=>'SomePassword123!',
    ]);
    HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$admin->id,
        'role'=>'admin','is_active'=>true,
    ]);
    Sanctum::actingAs($admin,['hotel:read','hotel:write']);
    return $hotel;
}

it('invites a new account with a hashed token without creating a membership', function (): void {
    Notification::fake();
    $hotel=inviteHotelAsAdmin();
    $email='new-'.uniqid().'@example.test';
    $base='/api/v1/hotels/'.$hotel->id.'/staff/invitations';
    $this->postJson($base,[
        'email'=>$email,'name'=>'New Cleaner','role'=>'housekeeping',
    ])->assertCreated()->assertJsonPath('data.email',$email)->assertJsonMissingPath('data.token');

    $stored=DB::table('staff_invitations')->where('hotel_id',$hotel->id)->first();
    expect($stored->token_hash)->toHaveLength(64);
    expect(User::where('email',$email)->exists())->toBeFalse();
    Notification::assertSentOnDemand(StaffInvitationNotification::class);
    $this->getJson($base)->assertOk()->assertJsonCount(1,'data')
        ->assertJsonMissing(['token_hash'=>$stored->token_hash]);
});

it('accepts exactly once, verifies email, creates the correct membership and audits onboarding', function (): void {
    Notification::fake();
    $hotel=inviteHotelAsAdmin();
    $email='accepted-'.uniqid().'@example.test';
    $this->postJson('/api/v1/hotels/'.$hotel->id.'/staff/invitations',[
        'email'=>$email,'name'=>'New Technician','role'=>'maintenance',
    ])->assertCreated();

    $token=null;
    Notification::assertSentOnDemand(StaffInvitationNotification::class,function ($notification) use (&$token): bool {
        $mail=$notification->toMail(new class {
            public string $name='New Technician';
            public function getEmailForPasswordReset():string{return 'ignored@example.test';}
        });
        $url=$mail->actionUrl;
        parse_str((string)parse_url($url,PHP_URL_QUERY),$params);
        $token=$params['token']??null;
        return is_string($token)&&strlen($token)===64;
    });

    $accept='/api/v1/auth/accept-invitation';
    $this->postJson($accept,[
        'token'=>$token,'password'=>'SecureWorkerPass123!',
        'password_confirmation'=>'SecureWorkerPass123!',
    ])->assertCreated();

    $user=User::where('email',$email)->firstOrFail();
    expect($user->email_verified_at)->not->toBeNull();
    expect(Hash::check('SecureWorkerPass123!',$user->password))->toBeTrue();
    expect(HotelMembership::where('hotel_id',$hotel->id)->where('user_id',$user->id)
        ->where('role','maintenance')->where('is_active',true)->exists())->toBeTrue();
    expect(DB::table('hotel_staff_events')->where('target_user_id',$user->id)
        ->where('action','invited')->count())->toBe(1);
    $this->postJson($accept,[
        'token'=>$token,'password'=>'SecureWorkerPass123!',
        'password_confirmation'=>'SecureWorkerPass123!',
    ])->assertUnprocessable()->assertJsonValidationErrors('token');
});

it('expires and revokes invitations before account creation', function (): void {
    Notification::fake();
    $hotel=inviteHotelAsAdmin();
    $base='/api/v1/hotels/'.$hotel->id.'/staff/invitations';
    $this->postJson($base,[
        'email'=>'expire-'.uniqid().'@example.test','name'=>'Temp Employee','role'=>'front_desk',
    ])->assertCreated();
    $row=DB::table('staff_invitations')->where('hotel_id',$hotel->id)->first();
    $revokedToken=str_repeat('a',64);
    DB::table('staff_invitations')->where('id',$row->id)->update(['token_hash'=>hash('sha256',$revokedToken)]);
    $this->patchJson($base.'/'.$row->id.'/revoke')->assertOk();
    $this->patchJson($base.'/'.$row->id.'/revoke')->assertNotFound();
    $this->postJson('/api/v1/auth/accept-invitation',[
        'token'=>$revokedToken,'password'=>'SecureWorkerPass123!',
        'password_confirmation'=>'SecureWorkerPass123!',
    ])->assertUnprocessable();
    $this->postJson($base,[
        'email'=>'expire-2-'.uniqid().'@example.test','name'=>'Temp Employee','role'=>'housekeeping',
    ])->assertCreated();
    $second=DB::table('staff_invitations')->orderByDesc('id')->first();
    $expiredToken=str_repeat('b',64);
    DB::table('staff_invitations')->where('id',$second->id)->update([
        'token_hash'=>hash('sha256',$expiredToken),
        'expires_at'=>now()->subMinute(),
    ]);
    $this->postJson('/api/v1/auth/accept-invitation',[
        'token'=>$expiredToken,'password'=>'SecureWorkerPass123!',
        'password_confirmation'=>'SecureWorkerPass123!',
    ])->assertUnprocessable();
});

it('blocks existing accounts, unsupported roles and other hotels', function (): void {
    Notification::fake();
    $hotel=inviteHotelAsAdmin();
    $other=Hotel::create(['name'=>'Other Invitation Hotel']);
    $base='/api/v1/hotels/'.$hotel->id.'/staff/invitations';
    $this->postJson($base,[
        'email'=>'existing-'.uniqid().'@example.test','name'=>'Test','role'=>'owner',
    ])->assertUnprocessable();
    $admin=User::where('name','Invitation Admin')->firstOrFail();
    $this->postJson($base,[
        'email'=>$admin->email,'name'=>'Existing Admin','role'=>'housekeeping',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->getJson('/api/v1/hotels/'.$other->id.'/staff/invitations')->assertNotFound();
});

it('restricts invitation management to active hotel administrators', function (): void {
    Notification::fake();
    $hotel=Hotel::create(['name'=>'Invite Permissions Hotel']);
    $base='/api/v1/hotels/'.$hotel->id.'/staff/invitations';
    $this->getJson($base)->assertUnauthorized();
    $user=User::create([
        'name'=>'Worker','email'=>'invite-worker-'.uniqid().'@example.test',
        'password'=>'SecureWorkerPass123!',
    ]);
    HotelMembership::create([
        'hotel_id'=>$hotel->id,'user_id'=>$user->id,
        'role'=>'housekeeping','is_active'=>true,
    ]);
    Sanctum::actingAs($user,['hotel:read','hotel:write']);
    $this->getJson($base)->assertForbidden();
    $this->postJson($base,[
        'email'=>'new-'.uniqid().'@example.test','name'=>'Someone','role'=>'front_desk',
    ])->assertForbidden();
});
