<?php

it('creates the tpl files', function () {
    $testPath = __DIR__ . '/../Tmp';
    config(['hestia.templates.nginx-proxy' => $testPath]);

    $this->artisan('reverse-proxy')->assertExitCode(0);

    $this->assertFileExists($testPath . '/reverse_proxy.tpl');
    $this->assertFileExists($testPath . '/reverse_proxy.stpl');

    unlink($testPath . '/reverse_proxy.tpl');
    unlink($testPath . '/reverse_proxy.stpl');
});
