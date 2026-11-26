<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class StaffMessagingRealtime
{
    public function enabled(): bool
    {
        return (bool) config('staff_messaging.realtime.enabled')
            && config('staff_messaging.realtime.app_id')
            && config('staff_messaging.realtime.key')
            && config('staff_messaging.realtime.secret')
            && $this->validEndpoint();
    }

    private function validEndpoint(): bool
    {
        $url=parse_url((string)config('staff_messaging.realtime.http_url'));
        return is_array($url) && ($url['scheme']??'')==='https'
            && !empty($url['host']) && !isset($url['user'],$url['pass'])
            && !isset($url['user']) && !isset($url['pass']) && !isset($url['query']) && !isset($url['fragment'])
            && in_array($url['path']??'', ['', '/'], true);
    }

    public function channel(int $hotel, int $user): string
    {
        return "private-staff.hotel.$hotel.user.$user";
    }

    public function authorize(string $socket, string $channel): string
    {
        return config('staff_messaging.realtime.key').':'.hash_hmac('sha256', $socket.':'.$channel, config('staff_messaging.realtime.secret'));
    }

    /** Send only a generic invalidation hint; authenticated API remains authoritative. */
    public function notify(int $hotel, iterable $users): void
    {
        if (!$this->enabled()) return;
        try {
            $channels = array_values(array_unique(array_map(fn ($user) => $this->channel($hotel,(int)$user), is_array($users)?$users:iterator_to_array($users))));
            foreach (array_chunk($channels, 100) as $batch) {
                $body = json_encode(['name'=>'staff-messaging.changed','channels'=>$batch,'data'=>'{}'], JSON_THROW_ON_ERROR);
                $path='/apps/'.rawurlencode((string)config('staff_messaging.realtime.app_id')).'/events';
                $params=['auth_key'=>config('staff_messaging.realtime.key'),'auth_timestamp'=>time(),'auth_version'=>'1.0','body_md5'=>md5($body)];
                ksort($params);
                $query=http_build_query($params,'','&',PHP_QUERY_RFC3986);
                $signature=hash_hmac('sha256',"POST\n".$path."\n".$query,config('staff_messaging.realtime.secret'));
                Http::withoutRedirecting()->connectTimeout(1)->timeout(2)->withBody($body,'application/json')->post(rtrim(config('staff_messaging.realtime.http_url'),'/').$path.'?'.$query.'&auth_signature='.$signature)->throw();
            }
        } catch (\Throwable $e) {
            // A committed message must never fail because the realtime service is down.
            Log::warning('Staff messaging realtime delivery unavailable.', ['exception_type'=>get_class($e)]);
        }
    }
}
