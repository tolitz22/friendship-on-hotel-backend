<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Hotel;
use App\Models\HotelMembership;
use App\Models\User;
use App\Http\Middleware\EnsureHotelPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HotelStaffController
{
    private const ROLES = ['admin','front_desk','housekeeping','maintenance'];

    private function staffList(Hotel $hotel): array
    {
        return $hotel->memberships()->with('user:id,name,email,is_active')
            ->orderBy('user_id')->get()
            ->map(fn ($m): array => [
                'userId'=>(string)$m->user_id,
                'name'=>$m->user->name,
                'email'=>$m->user->email,
                'role'=>$m->role,
                'isActive'=>(bool)$m->is_active,
                'accountActive'=>(bool)$m->user->is_active,
                'permissions'=>EnsureHotelPermission::effectiveForMembership($m),
                'customPermissions'=>$m->permission_overrides !== null,
            ])->all();
    }

    /**
     * Read-only role profiles from the same policy used by route authorization.
     * This is not a grant editor: independent approval and task ownership still apply.
     */
    public function permissions(Hotel $hotel): JsonResponse
    {
        $capabilities = [
            ['key'=>'booking.create','label'=>'Create bookings','group'=>'Bookings'],
            ['key'=>'booking.assign_room','label'=>'Assign rooms','group'=>'Bookings'],
            ['key'=>'booking.check_in','label'=>'Check in guests','group'=>'Bookings'],
            ['key'=>'booking.check_out','label'=>'Check out guests','group'=>'Bookings'],
            ['key'=>'reservation.read','label'=>'View reservations','group'=>'Bookings'],
            ['key'=>'guest.read','label'=>'View guest information','group'=>'Guest records'],
            ['key'=>'finance.read','label'=>'View guest bills','group'=>'Billing'],
            ['key'=>'cashier.write','label'=>'Record routine guest payments','group'=>'Billing'],
            ['key'=>'cashier.read','label'=>'View cashier shifts','group'=>'Billing'],
            ['key'=>'approval.request','label'=>'Request exceptions','group'=>'Management'],
            ['key'=>'approval.read','label'=>'View approval status','group'=>'Management'],
            ['key'=>'occupancy.read','label'=>'View occupancy information','group'=>'Operations'],
            ['key'=>'housekeeping.assigned','label'=>'Work on assigned housekeeping tasks','group'=>'Operations'],
            ['key'=>'maintenance.assigned','label'=>'Work on assigned maintenance tasks','group'=>'Operations'],
            ['key'=>'staff.manage','label'=>'Manage staff access','group'=>'Management'],
            ['key'=>'finance.approve','label'=>'Authorize restricted financial actions','group'=>'Management'],
        ];
        $scoped = [
            'admin'=>['housekeeping.assigned','maintenance.assigned','staff.manage','finance.approve'],
            'front_desk'=>[],
            'housekeeping'=>['housekeeping.assigned'],
            'maintenance'=>['maintenance.assigned'],
        ];
        $labels = [
            'admin'=>['label'=>'Manager','description'=>'Property administration, front desk and authorized financial reviews. Some operations require independent review.'],
            'front_desk'=>['label'=>'Front Desk','description'=>'Reservations, check-in/out, guest billing and routine cashier operations; no manager authorizations.'],
            'housekeeping'=>['label'=>'Housekeeping','description'=>'Update own assigned cleaning tasks and complete permitted physical inspections.'],
            'maintenance'=>['label'=>'Maintenance','description'=>'Update own assigned work orders without booking or financial access.'],
        ];
        $roles = [];
        foreach ($labels as $role => $details) {
            $permissions = array_values(array_unique(array_merge(
                \App\Http\Middleware\EnsureHotelPermission::forRole($role),
                $scoped[$role]
            )));
            $roles[] = [
                'key'=>$role,
                ...$details,
                'permissions'=>$permissions,
                'editablePermissions'=>EnsureHotelPermission::forRole($role),
                'activeStaff'=>$hotel->memberships()->where('role',$role)->where('is_active',true)->count(),
            ];
        }
        return response()->json([
            'data'=>[
                'roles'=>$roles,
                'capabilities'=>$capabilities,
                'note'=>'Role defaults only. Actual access is checked against the active hotel membership, authentication capabilities, task assignment, transaction state and independent-review requirements on every action.',
                'editable'=>true,
                'editScope'=>'Individual non-manager staff may use any subset of their role permissions. Manager privileges and sensitive finance approvals cannot be delegated.',
            ],
        ]);
    }

    public function index(Hotel $hotel): JsonResponse
    {
        return response()->json(['data'=>$this->staffList($hotel)]);
    }

    public function attach(Request $request, Hotel $hotel): JsonResponse
    {
        $data=$request->validate([
            'email'=>['required','email','max:255'],
            'role'=>['required',Rule::in(self::ROLES)],
        ]);
        $user=User::whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])->first();
        if (!$user || !$user->is_active) {
            throw ValidationException::withMessages(['email'=>['An existing active account with this email is required.']]);
        }

        DB::transaction(function () use ($hotel,$user,$data,$request): void {
            DB::table('hotels')->where('id',$hotel->id)->lockForUpdate()->first();
            if (HotelMembership::where('hotel_id',$hotel->id)->where('user_id',$user->id)->exists()) {
                throw ValidationException::withMessages(['email'=>['This account already belongs to the hotel.']]);
            }
            HotelMembership::create([
                'hotel_id'=>$hotel->id,'user_id'=>$user->id,
                'role'=>$data['role'],'is_active'=>true,
            ]);
            $this->audit($hotel,(int)$request->user()->id,(int)$user->id,'attached',null,['role'=>$data['role'],'isActive'=>true]);
        });
        return response()->json(['data'=>$this->staffList($hotel)],201);
    }

    public function history(Request $request, Hotel $hotel): JsonResponse
    {
        $input=$request->validate(['per_page'=>['sometimes','integer','min:1','max:50']]);
        $page=DB::table('hotel_staff_events')
            ->leftJoin('users as audit_actor','audit_actor.id','=','hotel_staff_events.actor_id')
            ->leftJoin('users as audit_target','audit_target.id','=','hotel_staff_events.target_user_id')
            ->where('hotel_staff_events.hotel_id',$hotel->id)->orderByDesc('hotel_staff_events.id')
            ->paginate((int)($input['per_page']??20),['hotel_staff_events.*','audit_actor.name as actor_name','audit_target.name as target_name']);
        return response()->json([
            'data'=>$page->getCollection()->map(fn(object $row):array=>[
                'id'=>(string)$row->id,'actorId'=>(string)$row->actor_id,
                'actorName'=>$row->actor_name,'targetName'=>$row->target_name,
                'targetUserId'=>(string)$row->target_user_id,'action'=>$row->action,
                'before'=>$row->before_state?json_decode($row->before_state,true):null,
                'after'=>json_decode($row->after_state,true),'createdAt'=>$row->created_at,
            ])->values(),
            'meta'=>['page'=>$page->currentPage(),'perPage'=>$page->perPage(),
                'total'=>$page->total(),'totalPages'=>$page->lastPage()],
        ]);
    }

    public function update(Request $request, Hotel $hotel, string $userId): JsonResponse
    {
        $data=$request->validate([
            'role'=>['sometimes','required',Rule::in(self::ROLES)],
            'isActive'=>['sometimes','required','boolean'],
            'expectedRole'=>['sometimes','required',Rule::in(self::ROLES)],
            'expectedIsActive'=>['sometimes','required','boolean'],
            'permissions'=>['sometimes','array','max:30'],
            'permissions.*'=>['string','distinct','max:80'],
            'expectedPermissions'=>['sometimes','array','max:30'],
            'expectedPermissions.*'=>['string','distinct','max:80'],
        ]);

        DB::transaction(function () use ($request,$hotel,$userId,$data): void {
            // Lock the hotel to serialize membership changes and preserve at least one administrator.
            DB::table('hotels')->where('id',$hotel->id)->lockForUpdate()->first();
            $m=HotelMembership::where('hotel_id',$hotel->id)
                ->where('user_id',$userId)->lockForUpdate()->firstOrFail();
            // Fail closed if another manager changed this staff membership after
            // the editor loaded. Prevents silent permission overwrites.
            if ((array_key_exists('expectedRole',$data) && $data['expectedRole']!==$m->role)
                || (array_key_exists('expectedIsActive',$data)
                    && (bool)$data['expectedIsActive']!==(bool)$m->is_active)) {
                abort(409, 'This employee’s access changed. Refresh staff before saving.');
            }
            if (array_key_exists('expectedPermissions',$data)
                && array_values($data['expectedPermissions']) !== EnsureHotelPermission::effectiveForMembership($m)) {
                abort(409, 'This employee’s permissions changed. Refresh staff before saving.');
            }
            if (array_key_exists('permissions',$data)) {
                if ($m->role === 'admin' || (string)$request->user()->id === (string)$m->user_id) {
                    throw ValidationException::withMessages(['permissions'=>['Manager permissions cannot be customized.']]);
                }
                if (array_diff($data['permissions'],EnsureHotelPermission::forRole($m->role))) {
                    throw ValidationException::withMessages(['permissions'=>['Permissions must belong to the employee’s existing role.']]);
                }
            }
            $nextRole=$data['role']??$m->role;
            $nextActive=$data['isActive']??$m->is_active;
            if ((string)$request->user()->id===(string)$m->user_id
                && (!$nextActive || $nextRole!=='admin')) {
                throw ValidationException::withMessages(['role'=>['You cannot remove your own administrator access.']]);
            }
            if ($m->role==='admin' && $m->is_active && (!$nextActive || $nextRole!=='admin')) {
                $count=HotelMembership::where('hotel_id',$hotel->id)
                    ->where('role','admin')->where('is_active',true)->count();
                if ($count<=1) {
                    throw ValidationException::withMessages(['role'=>['The hotel must keep an active administrator.']]);
                }
            }
            $base = EnsureHotelPermission::forRole($nextRole);
            $chosen = array_key_exists('permissions',$data)
                ? array_values(array_intersect($base,$data['permissions']))
                : ($nextRole !== $m->role ? $base : EnsureHotelPermission::effectiveForMembership($m));
            $override = $nextRole === 'admin' || $chosen === $base ? null : $chosen;
            $before=['role'=>$m->role,'isActive'=>(bool)$m->is_active,
                'permissions'=>EnsureHotelPermission::effectiveForMembership($m)];
            $after=['role'=>$nextRole,'isActive'=>(bool)$nextActive,'permissions'=>$chosen];
            if ($before!==$after) {
                $m->update(['role'=>$nextRole,'is_active'=>$nextActive,'permission_overrides'=>$override]);
                $this->audit($hotel,(int)$request->user()->id,(int)$m->user_id,
                    array_key_exists('permissions',$data)?'permissions_updated':'updated',$before,$after);
            }
        });

        return response()->json(['data'=>$this->staffList($hotel)]);
    }

    private function audit(Hotel $hotel,int $actor,int $target,string $action,?array $before,array $after): void
    {
        DB::table('hotel_staff_events')->insert([
            'hotel_id'=>$hotel->id,'actor_id'=>$actor,'target_user_id'=>$target,
            'action'=>$action,'before_state'=>$before===null?null:json_encode($before,JSON_THROW_ON_ERROR),
            'after_state'=>json_encode($after,JSON_THROW_ON_ERROR),'created_at'=>now(),
        ]);
    }
}
