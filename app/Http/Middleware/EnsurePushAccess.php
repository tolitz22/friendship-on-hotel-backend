<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Hotel;
use App\Services\PushRecipientScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePushAccess
{
    private function allowed(Request $request,Hotel $hotel): bool
    {
        $role=app(PushRecipientScope::class)->role($hotel,(int)$request->user()->id);
        return $role!==null&&$request->user()->tokenCan('hotel:read')&&($role!=='admin'||$request->user()->tokenCan('hotel:write'));
    }

    public function handle(Request $request,Closure $next): Response
    {
        $hotel=$request->route('hotel');
        abort_unless($hotel instanceof Hotel&&$this->allowed($request,$hotel),403);
        return $next($request);
    }

    public function capability(Request $request,Hotel $hotel): Response
    {
        return response()->json(['data'=>['allowed'=>$this->allowed($request,$hotel)]]);
    }
}
