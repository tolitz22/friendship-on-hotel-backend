<?php

use App\Logging\TechnicalLogArchive;

it('only archives completed dedicated technical logs and never removes the originals', function (): void {
    $root = sys_get_temp_dir().'/pms-archive-'.bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $destination = $root.'/private';
    $name = 'pms-technical-2026-11-26.log';
    $payload = '{"message":"pms.logging.smoke"}'."\n";
    try {
        file_put_contents($root.'/'.$name, $payload);
        file_put_contents($root.'/laravel.log', 'legacy');
        file_put_contents($root.'/pms-technical-2026-11-27.log', 'current');
        file_put_contents($root.'/pms-technical-2026-02-30.log', 'invalid date');
        symlink($root.'/laravel.log', $root.'/pms-technical-2026-11-25.log');
        $archive = new TechnicalLogArchive;
        expect($archive->candidates($root, '2026-11-27'))->toBe([$name]);
        expect($archive->archive($root, $destination, $name, '2026-11-27'))->toBeTrue();
        expect(gzdecode(file_get_contents($destination.'/'.$name.'.gz')))->toBe($payload);
        expect(file_get_contents($root.'/'.$name))->toBe($payload);
        expect($archive->archive($root, $destination, $name, '2026-11-27'))->toBeFalse();
        expect(fn () => $archive->archive($root, $destination, 'laravel.log', '2026-11-27'))->toThrow(RuntimeException::class);
        expect(file_get_contents($root.'/laravel.log'))->toBe('legacy');
    } finally {
        foreach (glob($destination.'/*') ?: [] as $file) unlink($file);
        if (is_dir($destination)) rmdir($destination);
        foreach (glob($root.'/*') ?: [] as $file) unlink($file);
        rmdir($root);
    }
});
