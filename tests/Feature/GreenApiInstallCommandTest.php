<?php

namespace Ges\LaravelGreenApi\Tests\Feature;

use Ges\LaravelGreenApi\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;

class GreenApiInstallCommandTest extends TestCase
{
    public function test_install_command_is_registered_under_package_short_name(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey('green-api:install', $commands);
        $this->assertArrayNotHasKey('laravel-green-api:install', $commands);
    }
}
