<?php

it('it creates a custom tpl file', function () {
    $testPath = __DIR__ . '/../Tmp';
    config(['hestia.templates.php-fpm' => $testPath]);

    $this->artisan('php-template ee --no-interaction --phpVersion=7.4 --maxChildren=20 --processIdleTimeout=10 --maxRequests=20000')->assertExitCode(0);

    $this->assertFileExists($testPath . '/ee-PHP-7_4.tpl');

    $contents = file_get_contents($testPath . '/ee-PHP-7_4.tpl');
    $this->assertStringContainsString('pm.max_children = 20', $contents);
    $this->assertStringContainsString('pm.process_idle_timeout = 10s', $contents);
    $this->assertStringContainsString('pm.max_requests = 20000', $contents);

    unlink($testPath . '/ee-PHP-7_4.tpl');
});
