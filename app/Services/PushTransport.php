<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

final class PushTransport
{
    public function make(?ClientInterface $client = null): WebPush
    {
        // Web Push v11 accepts a PSR-18 client, not a numeric timeout.
        // Disable redirects so a provider cannot redirect encrypted credentials
        // to a destination outside the registered endpoint.
        $client ??= new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'verify' => true,
        ]);
        $factory = new HttpFactory;

        return new WebPush(['VAPID' => [
            'subject' => config('push.subject'),
            'publicKey' => config('push.public_key'),
            'privateKey' => config('push.private_key'),
        ]], [], $client, $factory, $factory);
    }
}
