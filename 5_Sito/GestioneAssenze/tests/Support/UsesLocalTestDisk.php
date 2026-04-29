<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

trait UsesLocalTestDisk
{
    protected string $testDiskRoot;

    protected function setUpLocalTestDisk(string $prefix): void
    {
        $this->testDiskRoot = rtrim(sys_get_temp_dir(), '\\/')
            .DIRECTORY_SEPARATOR
            .$prefix
            .uniqid('', true);

        File::ensureDirectoryExists($this->testDiskRoot);

        config()->set('filesystems.default', 'local');
        config()->set('filesystems.disks.local.root', $this->testDiskRoot);
        app('filesystem')->forgetDisk('local');
    }

    protected function tearDownLocalTestDisk(): void
    {
        app('filesystem')->forgetDisk('local');

        if (isset($this->testDiskRoot) && $this->testDiskRoot !== '') {
            File::deleteDirectory($this->testDiskRoot);
        }
    }
}
