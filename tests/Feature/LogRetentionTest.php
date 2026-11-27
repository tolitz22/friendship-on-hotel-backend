<?php

it('reports provider-controlled stderr retention without requiring database changes', function (): void {
    config(['logging.default' => 'stderr']);
    $this->artisan('pms:logs-retention-check')->expectsOutput('stderr: retention and archival are controlled by the hosting/logging provider.')->assertSuccessful();
});

it('warns when legacy single-file logging is unbounded', function (): void {
    config(['logging.default' => 'stack', 'logging.channels.stack.channels' => ['single']]);
    $this->artisan('pms:logs-retention-check')->expectsOutput('Legacy single-file channel is unbounded; prefer stderr or pms_daily.')->assertFailed();
});

it('reports the bounded dedicated file channel', function (): void {
    config(['logging.default' => 'pms_daily', 'logging.channels.pms_daily.days' => 14]);
    $this->artisan('pms:logs-retention-check')->expectsOutput('Dedicated rotating log files: maximum 14 daily files.')->assertSuccessful();
});

it('defaults archival to a read-only preview', function (): void {
    $this->artisan('pms:logs-archive')->expectsOutput('Dry run. No files or database records modified. Use --write to create private gzip copies.')->assertSuccessful();
});
