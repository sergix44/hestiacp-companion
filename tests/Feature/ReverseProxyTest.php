<?php

it('creates the tpl files', function () {
    $testPath = __DIR__ . '/../Tmp';
    config(['hestia.templates.nginx-proxy' => $testPath]);

    $this->artisan('reverse-proxy')->assertExitCode(0);

    $this->assertFileExists($testPath . '/http_reverse_proxy.tpl');
    $this->assertFileExists($testPath . '/http_reverse_proxy.stpl');
    $this->assertFileExists($testPath . '/https_reverse_proxy.tpl');
    $this->assertFileExists($testPath . '/https_reverse_proxy.stpl');
    $this->assertFileExists($testPath . '/unix_reverse_proxy.tpl');
    $this->assertFileExists($testPath . '/unix_reverse_proxy.stpl');

    unlink($testPath . '/http_reverse_proxy.tpl');
    unlink($testPath . '/http_reverse_proxy.stpl');
    unlink($testPath . '/https_reverse_proxy.tpl');
    unlink($testPath . '/https_reverse_proxy.stpl');
    unlink($testPath . '/unix_reverse_proxy.tpl');
    unlink($testPath . '/unix_reverse_proxy.stpl');
});
