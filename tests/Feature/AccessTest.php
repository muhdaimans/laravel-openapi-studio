<?php

namespace OpenApiStudio\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use OpenApiStudio\Tests\TestCase;

class AccessTest extends TestCase
{
    public function test_editor_page_loads_locally(): void
    {
        $this->get($this->url())->assertOk()->assertSee('OpenAPI Studio');
    }

    public function test_assets_are_served(): void
    {
        $this->get($this->url('assets/app.js'))->assertOk();
        $this->get($this->url('assets/secret.php'))->assertNotFound();
    }

    #[DataProvider('blockedEnvironments')]
    public function test_editor_is_blocked_outside_local(string $env): void
    {
        $this->app['env'] = $env;

        $this->get($this->url())->assertNotFound();
        $this->getJson($this->url('api/specs'))->assertNotFound();
        $this->postJson($this->url('api/specs'), ['title' => 'X'])->assertNotFound();
    }

    public static function blockedEnvironments(): array
    {
        return [['staging'], ['production'], ['testing']];
    }

    public function test_editor_can_be_switched_off(): void
    {
        config(['openapi-studio.enabled' => false]);

        $this->get($this->url())->assertNotFound();
    }
}
