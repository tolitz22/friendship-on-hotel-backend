<?php

declare(strict_types=1);

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class PushTransport
{
    public function runtimeIssues(): array
    {
        $issues = [];
        foreach (['curl', 'mbstring', 'openssl'] as $extension) {
            if (! extension_loaded($extension)) {
                $issues[] = 'transport_extension_missing:'.$extension;
            }
        }
        if (extension_loaded('openssl')) {
            if (! in_array('prime256v1', openssl_get_curve_names() ?: [], true)) {
                $issues[] = 'transport_curve_missing:prime256v1';
            }
            if (! in_array('aes-128-gcm', openssl_get_cipher_methods(), true)) {
                $issues[] = 'transport_cipher_missing:aes-128-gcm';
            }
        }
        if (! in_array('sha256', hash_hmac_algos(), true)) {
            $issues[] = 'transport_hash_missing:sha256';
        }

        return $issues;
    }

    public function make(?ClientInterface $client = null): WebPush
    {
        $issues = $this->runtimeIssues();
        if ($issues !== []) {
            throw new RuntimeException('transport_runtime_unavailable');
        }

        // Web Push v11 accepts a PSR-18 client, not a numeric timeout.
        $client ??= new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'verify' => true,
        ]);
        $factory = new HttpFactory;

        // v11 triggers E_USER_NOTICE without a logger when GMP/BCMath are
        // absent. Laravel converts PHP notices into exceptions. These two
        // extensions are optional accelerators, not correctness requirements.
        // Required extensions/algorithms have been checked explicitly above.
        // Never pass provider messages or key material into the application log.
        return new WebPush(['VAPID' => [
            'subject' => config('push.subject'),
            'publicKey' => config('push.public_key'),
            'privateKey' => config('push.private_key'),
        ]], [], $client, $factory, $factory, null, new NullLogger);
    }

    public function diagnostic(\Throwable $failure): string
    {
        // Exception messages, traces and arguments can contain credentials.
        return 'transport_exception_type:'.$failure::class;
    }
}
