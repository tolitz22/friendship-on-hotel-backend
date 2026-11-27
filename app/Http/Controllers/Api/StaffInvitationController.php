<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Logging\SecurityLog;
use App\Models\HotelMembership;
use App\Models\User;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

final class StaffInvitationController
{
    private const ROLES=['front_desk','housekeeping','maintenance','admin'];
    private const HOURS=48;

    public function index(Hotel $hotel): JsonResponse
    {
        $items=DB::table('staff_invitations')
            ->where('hotel_id',$hotel->id)->orderByDesc('id')->limit(100)->get();
        return response()->json(['data'=>$items->map(fn(object $item):array=>[
            'id'=>(string)$item->id,
            'email'=>$item->email,
            'name'=>$item->name,
            'role'=>$item->role,
            'expiresAt'=>$item->expires_at,
            'acceptedAt'=>$item->accepted_at,
            'revokedAt'=>$item->revoked_at,
            'createdAt'=>$item->created_at,
        ])->values()]);
    }

    public function store(Request $request, Hotel $hotel): JsonResponse
    {
        $data=$request->validate([
            'email'=>['required','email','max:255'],
            'name'=>['required','string','min:2','max:120'],
            'role'=>['required',Rule::in(self::ROLES)],
        ]);
        $email=strtolower(trim($data['email']));
        $name=trim($data['name']);
        if ($name==='') throw ValidationException::withMessages(['name'=>['Name is required.']]);
        if (User::whereRaw('LOWER(email) = ?',[$email])->exists()) {
            throw ValidationException::withMessages(['email'=>['This address already has an account. Add it through the existing staff directory.']]);
        }

        $token=Str::random(64);
        $id=DB::transaction(function () use ($hotel,$request,$data,$email,$name,$token): int {
            // Serializes invitations from this property; re-inviting rotates old links.
            DB::table('hotels')->where('id',$hotel->id)->lockForUpdate()->first();
            app(\App\Services\PlatformSubscriptionCapacity::class)->requireSpace((int) $hotel->id, 'staff');
            DB::table('staff_invitations')
                ->where('hotel_id',$hotel->id)->where('email',$email)
                ->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['revoked_at'=>now(),'updated_at'=>now()]);
            return DB::table('staff_invitations')->insertGetId([
                'hotel_id'=>$hotel->id,
                'invited_by'=>$request->user()->id,
                'email'=>$email,
                'name'=>$name,
                'role'=>$data['role'],
                'token_hash'=>hash('sha256',$token),
                'expires_at'=>now()->addHours(self::HOURS),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]);
        });

        try {
            Notification::route('mail',$email)->notify(
                new StaffInvitationNotification($token,$hotel->name,$data['role'])
            );
        } catch (\Throwable $exception) {
            report($exception);
            // Do not leave an invitation marked usable if sending failed.
            DB::table('staff_invitations')->where('id',$id)->whereNull('accepted_at')
                ->update(['revoked_at'=>now(),'updated_at'=>now()]);
            return response()->json(['message'=>'Invitation delivery failed. Check email configuration and send a new invitation.'],503);
        }
        SecurityLog::record('pms.security.invitation.sent', ['hotel_id' => (int) $hotel->id, 'actor_id' => (int) $request->user()->id, 'invitation_id' => (int) $id]);
        return response()->json([
            'data'=>['id'=>(string)$id,'email'=>$email,'expiresInHours'=>self::HOURS],
            'message'=>'Invitation email sent.',
        ],201);
    }

    public function revoke(Request $request, Hotel $hotel, string $invitation): JsonResponse
    {
        $updated=DB::table('staff_invitations')->where('hotel_id',$hotel->id)
            ->where('id',$invitation)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at'=>now(),'updated_at'=>now()]);
        abort_if($updated===0,404);
        SecurityLog::record('pms.security.invitation.revoked', ['hotel_id' => (int) $hotel->id, 'actor_id' => (int) $request->user()->id, 'invitation_id' => (int) $invitation]);
        return response()->json(['message'=>'Invitation revoked.']);
    }

    public function accept(Request $request): JsonResponse
    {
        $data=$request->validate([
            'token'=>['required','string','size:64'],
            'password'=>['required','confirmed',PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        $result=DB::transaction(function () use ($data): array {
            $hash = hash('sha256', $data['token']);
            $candidate = DB::table('staff_invitations')->where('token_hash', $hash)->first(['hotel_id']);
            if ($candidate) {
                DB::table('hotels')->where('id', $candidate->hotel_id)->lockForUpdate()->first();
            }
            $invite = $candidate ? DB::table('staff_invitations')->where('token_hash', $hash)
                ->where('hotel_id', $candidate->hotel_id)->lockForUpdate()->first() : null;
            if (!$invite||$invite->accepted_at||$invite->revoked_at||now()->greaterThanOrEqualTo($invite->expires_at)) {
                throw ValidationException::withMessages(['token'=>['Invalid, expired, or already-used invitation.']]);
            }
            // Other property administrators might also invite this email. Never take
            // ownership of an existing user or silently elevate an existing account.
            if (User::whereRaw('LOWER(email) = ?',[$invite->email])->exists()) {
                throw ValidationException::withMessages(['token'=>['An account already exists for this email. Ask your administrator to add your existing account.']]);
            }
            app(\App\Services\PlatformSubscriptionCapacity::class)->requireSpace((int) $invite->hotel_id, 'staff');
            $user=User::create([
                'name'=>$invite->name,
                'email'=>$invite->email,
                'password'=>Hash::make($data['password']),
                'email_verified_at'=>now(),
                'is_active'=>true,
            ]);
            $user->forceFill(['email_verified_at'=>now()])->save();
            HotelMembership::create([
                'hotel_id'=>$invite->hotel_id,'user_id'=>$user->id,
                'role'=>$invite->role,'is_active'=>true,
            ]);
            DB::table('staff_invitations')->where('id',$invite->id)->update([
                'accepted_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('hotel_staff_events')->insert([
                'hotel_id'=>$invite->hotel_id,
                'actor_id'=>$invite->invited_by,
                'target_user_id'=>$user->id,
                'action'=>'invited',
                'before_state'=>null,
                'after_state'=>json_encode(['role'=>$invite->role,'isActive'=>true],JSON_THROW_ON_ERROR),
                'created_at'=>now(),
            ]);
            return ['hotelId'=>(string)$invite->hotel_id, 'userId'=>(int)$user->id, 'invitationId'=>(int)$invite->id];
        },3);

        SecurityLog::record('pms.security.invitation.accepted', ['hotel_id' => (int) $result['hotelId'], 'user_id' => $result['userId'], 'invitation_id' => $result['invitationId']]);
        return response()->json([
            'message'=>'Account created. Sign in with your new password.',
            'data'=>['hotelId'=>$result['hotelId']],
        ],201);
    }
}
