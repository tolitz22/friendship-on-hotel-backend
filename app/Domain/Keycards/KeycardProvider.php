<?php

declare(strict_types=1);

namespace App\Domain\Keycards;

/**
 * A request authorization result is NOT a physically encoded keycard.
 * Future hardware providers require explicit configuration and vendor testing.
 */
interface KeycardProvider
{
    /** @return array{provider:string,reference:string,physicalCardEncoded:bool} */
    public function authorize(int $hotelId, int $authorizationId): array;
}
