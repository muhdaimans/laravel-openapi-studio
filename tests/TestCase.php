<?php

namespace OpenApiStudio\Tests;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Orchestra;
use OpenApiStudio\OpenApiStudioServiceProvider;

abstract class TestCase extends Orchestra
{
    protected string $specDir;

    protected function setUp(): void
    {
        $this->specDir = sys_get_temp_dir().'/openapi-studio-tests-'.bin2hex(random_bytes(4));
        parent::setUp();

        // CSRF is only skipped automatically in the "testing" env; these tests run as "local".
        $this->withoutMiddleware(array_filter([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
        ], 'class_exists'));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->specDir);
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [OpenApiStudioServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = 'local';
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('openapi-studio.directory', $this->specDir);
    }

    protected function putSpec(string $name, string $contents): void
    {
        (new Filesystem)->ensureDirectoryExists($this->specDir);
        file_put_contents($this->specDir.'/'.$name, $contents);
    }

    protected function url(string $path = ''): string
    {
        return '/openapi-studio'.($path === '' ? '' : '/'.$path);
    }
}
