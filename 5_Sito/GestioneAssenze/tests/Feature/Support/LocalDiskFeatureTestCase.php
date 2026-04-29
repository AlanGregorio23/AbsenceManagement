<?php

namespace Tests\Feature\Support;

use Carbon\Carbon;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsInfoOperationLogs;
use Tests\Support\ProvidesSignatureDataUri;
use Tests\Support\UsesLocalTestDisk;
use Tests\TestCase;

abstract class LocalDiskFeatureTestCase extends TestCase
{
    use AssertsInfoOperationLogs;
    use ProvidesSignatureDataUri;
    use RefreshDatabase;
    use UsesLocalTestDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->setUpLocalTestDisk($this->localTestDiskPrefix());
    }

    protected function tearDown(): void
    {
        $this->tearDownLocalTestDisk();
        Carbon::setTestNow();

        parent::tearDown();
    }

    abstract protected function localTestDiskPrefix(): string;
}
