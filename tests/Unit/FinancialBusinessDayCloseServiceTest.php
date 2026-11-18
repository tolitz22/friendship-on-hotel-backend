<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Finance\FinancialBusinessDayCloseService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FinancialBusinessDayCloseServiceTest extends TestCase
{
    public static function invalidArguments(): array
    {
        return [
            'missing hotel' => [0, '2026-11-18', 1],
            'missing actor' => [1, '2026-11-18', 0],
            'invalid month' => [1, '2026-13-18', 1],
            'invalid leap day' => [1, '2025-02-29', 1],
            'non ISO date' => [1, '11/18/2026', 1],
        ];
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_close_arguments_are_rejected_before_database_access(
        int $hotelId,
        string $businessDate,
        int $actorId,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        (new FinancialBusinessDayCloseService())->close($hotelId, $businessDate, $actorId);
    }
    public function test_iso_business_date_validation_accepts_real_calendar_dates(): void
    {
        $validator = new \ReflectionMethod(FinancialBusinessDayCloseService::class, 'isValidDate');
        $service = new FinancialBusinessDayCloseService();

        self::assertTrue($validator->invoke($service, '2026-11-18'));
        self::assertTrue($validator->invoke($service, '2024-02-29'));
        self::assertFalse($validator->invoke($service, '2025-02-29'));
        self::assertFalse($validator->invoke($service, '2026-13-18'));
        self::assertFalse($validator->invoke($service, '2026-11-18T00:00:00'));
        self::assertFalse($validator->invoke($service, "2026-11-18\n"));
    }

}
