<?php

declare(strict_types=1);

it('responds with a versioned health status', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('version', 'v1');
});
