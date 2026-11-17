<?php

namespace OpenApiStudio\Tests\Feature;

use Illuminate\Http\UploadedFile;
use OpenApiStudio\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class SpecCrudTest extends TestCase
{
    private const SAMPLE = <<<'YAML'
openapi: 3.0.3
info:
  title: Orders API
  version: '1.0'
x-team: payments
paths:
  /orders:
    get:
      summary: List orders
      responses:
        '200':
          description: OK
          content:
            application/json:
              schema: {}
              example:
                created: 2024-01-31
components:
  schemas:
    Order:
      type: object
      properties:
        id:
          type: integer
YAML;

    public function test_create_lists_and_reads_a_spec(): void
    {
        $this->postJson($this->url('api/specs'), ['title' => 'Billing API', 'version' => '2.0.0'])
            ->assertCreated()
            ->assertJson(['name' => 'billing-api.yaml']);

        $this->assertFileExists($this->specDir.'/billing-api.yaml');
        $this->assertFalse(is_executable($this->specDir.'/billing-api.yaml'), 'Spec files must not be executable.');

        $this->getJson($this->url('api/specs'))
            ->assertOk()
            ->assertJsonPath('specs.0.name', 'billing-api.yaml')
            ->assertJsonPath('specs.0.title', 'Billing API');

        $this->getJson($this->url('api/specs/billing-api.yaml'))
            ->assertOk()
            ->assertJsonPath('spec.info.version', '2.0.0')
            ->assertJsonPath('format', 'yaml');
    }

    public function test_create_refuses_existing_file(): void
    {
        $this->putSpec('orders.yaml', self::SAMPLE);

        $this->postJson($this->url('api/specs'), ['title' => 'Orders', 'name' => 'orders.yaml'])->assertStatus(409);
    }

    public function test_update_writes_yaml_and_keeps_a_backup(): void
    {
        $this->putSpec('orders.yaml', self::SAMPLE);
        $read = $this->getJson($this->url('api/specs/orders.yaml'))->json();

        $spec = $read['spec'];
        $spec['info']['title'] = 'Orders API v2';

        $this->putJson($this->url('api/specs/orders.yaml'), ['spec' => $spec, 'hash' => $read['hash']])
            ->assertOk()
            ->assertJsonStructure(['hash']);

        $saved = Yaml::parseFile($this->specDir.'/orders.yaml');
        $this->assertSame('Orders API v2', $saved['info']['title']);
        $this->assertSame('payments', $saved['x-team'], 'Unknown fields must survive a save.');
        $this->assertCount(1, glob($this->specDir.'/.backups/orders.yaml.*.bak'));
    }

    public function test_round_trip_keeps_empty_maps_and_dates(): void
    {
        $this->putSpec('orders.yaml', self::SAMPLE);
        $read = $this->getJson($this->url('api/specs/orders.yaml'));

        // json_decode with objects so {} stays an object, as the browser sends it
        $body = json_decode($read->getContent());
        $payload = json_encode(['spec' => $body->spec, 'hash' => $body->hash]);

        $this->call('PUT', $this->url('api/specs/orders.yaml'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $payload)
            ->assertOk();

        $raw = file_get_contents($this->specDir.'/orders.yaml');
        $this->assertMatchesRegularExpression('/schema: \{\s*\}/', $raw);
        $this->assertMatchesRegularExpression("/created: '?2024-01-31'?/", $raw);
    }

    public function test_update_detects_changes_made_on_disk(): void
    {
        $this->putSpec('orders.yaml', self::SAMPLE);
        $read = $this->getJson($this->url('api/specs/orders.yaml'))->json();

        $this->putSpec('orders.yaml', str_replace('Orders API', 'Changed elsewhere', self::SAMPLE));

        $this->putJson($this->url('api/specs/orders.yaml'), ['spec' => $read['spec'], 'hash' => $read['hash']])
            ->assertStatus(409);

        $this->putJson($this->url('api/specs/orders.yaml'), ['spec' => $read['spec'], 'hash' => $read['hash'], 'force' => true])
            ->assertOk();
    }

    public function test_update_rejects_invalid_spec(): void
    {
        $this->putSpec('orders.yaml', self::SAMPLE);

        $this->putJson($this->url('api/specs/orders.yaml'), ['spec' => ['openapi' => '3.0.3', 'info' => ['title' => '']]])
            ->assertStatus(422)
            ->assertJsonFragment(['The API title (info.title) is required.']);

        $this->assertStringContainsString('Orders API', file_get_contents($this->specDir.'/orders.yaml'));
    }

    public function test_json_files_are_saved_as_json(): void
    {
        $this->postJson($this->url('api/specs'), ['title' => 'Json API', 'format' => 'json'])
            ->assertCreated()->assertJson(['name' => 'json-api.json']);

        $decoded = json_decode(file_get_contents($this->specDir.'/json-api.json'), true);
        $this->assertSame('Json API', $decoded['info']['title']);
    }

    public function test_delete_removes_file_and_keeps_backup(): void
    {
        $this->putSpec('orders.yaml', self::SAMPLE);

        $this->deleteJson($this->url('api/specs/orders.yaml'))->assertOk();

        $this->assertFileDoesNotExist($this->specDir.'/orders.yaml');
        $this->assertCount(1, glob($this->specDir.'/.backups/orders.yaml.*.bak'));
        $this->getJson($this->url('api/specs/orders.yaml'))->assertNotFound();
    }

    public function test_file_names_cannot_escape_the_directory(): void
    {
        $this->getJson($this->url('api/specs/..%2F..%2F.env'))->assertNotFound();
        $this->getJson($this->url('api/specs/.env'))->assertNotFound();
        $this->postJson($this->url('api/specs'), ['title' => 'X', 'name' => '../evil.yaml'])->assertStatus(422);
        $this->getJson($this->url('api/specs/notes.txt'))->assertStatus(422);
    }

    public function test_import_validates_and_handles_existing_files(): void
    {
        $file = UploadedFile::fake()->createWithContent('orders.yaml', self::SAMPLE);
        $this->post($this->url('api/import'), ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()->assertJson(['name' => 'orders.yaml']);

        $again = UploadedFile::fake()->createWithContent('orders.yaml', self::SAMPLE);
        $this->post($this->url('api/import'), ['file' => $again], ['Accept' => 'application/json'])->assertStatus(409);

        $again = UploadedFile::fake()->createWithContent('orders.yaml', self::SAMPLE);
        $this->post($this->url('api/import'), ['file' => $again, 'overwrite' => '1'], ['Accept' => 'application/json'])->assertCreated();

        $swagger2 = UploadedFile::fake()->createWithContent('old.yaml', "swagger: '2.0'\ninfo: {title: Old, version: '1'}\npaths: {}\n");
        $this->post($this->url('api/import'), ['file' => $swagger2], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonFragment(['Swagger 2.0 files are not supported. Convert the file to OpenAPI 3 first.']);

        $broken = UploadedFile::fake()->createWithContent('broken.yaml', "openapi: 3.0.3\ninfo: [unclosed\n");
        $this->post($this->url('api/import'), ['file' => $broken], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_export_in_both_formats(): void
    {
        $this->putSpec('orders.yaml', self::SAMPLE);

        $yaml = $this->get($this->url('api/specs/orders.yaml/export?format=yaml'))->assertOk();
        $this->assertSame(self::SAMPLE, $yaml->getContent());
        $this->assertStringContainsString('attachment; filename="orders.yaml"', $yaml->headers->get('Content-Disposition'));

        $json = $this->get($this->url('api/specs/orders.yaml/export?format=json'))->assertOk();
        $this->assertSame('Orders API', json_decode($json->getContent(), true)['info']['title']);
        $this->assertStringContainsString('orders.json', $json->headers->get('Content-Disposition'));
    }

    public function test_convert_endpoints(): void
    {
        $this->postJson($this->url('api/convert/parse'), ['yaml' => self::SAMPLE])
            ->assertOk()
            ->assertJsonPath('spec.info.title', 'Orders API')
            ->assertJsonPath('errors', []);

        $this->postJson($this->url('api/convert/parse'), ['yaml' => "a: [b"])->assertStatus(422);

        $dumped = $this->postJson($this->url('api/convert/dump'), ['spec' => ['openapi' => '3.0.3']])->json('yaml');
        $this->assertSame("openapi: 3.0.3\n", $dumped);
    }
}
