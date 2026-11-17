<?php

declare(strict_types=1);

namespace App\Domain\Keycards;

final class MockKeycardProvider implements KeycardProvider
{
    public function authorize(int $hotelId, int $authorizationId): array
    {
        return [
            'provider' => 'mock',
            'reference' => 'MOCK-'.$hotelId.'-'.$authorizationId,
            'physicalCardEncoded' => false,
        ];
    }
}
