<?php

namespace OpenApiStudio\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use OpenApiStudio\Exceptions\SpecException;
use OpenApiStudio\SpecRepository;
use OpenApiStudio\Support\SpecSerializer;
use OpenApiStudio\Support\SpecValidator;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class SpecController extends Controller
{
    public function __construct(
        private SpecRepository $specs,
        private SpecSerializer $serializer,
        private SpecValidator $validator,
    ) {}

    public function index(): JsonResponse
    {
        return $this->json(['directory' => $this->specs->directory(), 'specs' => $this->specs->all()]);
    }

    public function show(string $name): JsonResponse
    {
        $read = $this->specs->read($name);

        return $this->json([
            'name' => $name,
            'format' => SpecSerializer::formatFor($name),
            'hash' => $read['hash'],
            'spec' => $read['spec'],
        ]);
    }

    /**
     * Body: { name?: string, title: string, version?: string, description?: string, format?: "yaml"|"json" }
     */
    public function store(Request $request): JsonResponse
    {
        $body = $this->body($request);

        $title = trim((string) ($body->title ?? ''));
        if ($title === '') {
            throw SpecException::invalid(['The API title is required.']);
        }

        $format = ($body->format ?? 'yaml') === 'json' ? 'json' : 'yaml';
        $name = $this->normalizeName((string) ($body->name ?? '') ?: Str::slug($title), $format);

        $spec = $this->specs->skeleton(
            $title,
            trim((string) ($body->version ?? '')) ?: '1.0.0',
            trim((string) ($body->description ?? '')),
        );

        $hash = $this->specs->create($name, $spec);

        return $this->json(['name' => $name, 'hash' => $hash], 201);
    }

    /**
     * Body: { spec: object, hash?: string|null, force?: bool }
     */
    public function update(Request $request, string $name): JsonResponse
    {
        $body = $this->body($request);

        if (! property_exists($body, 'spec')) {
            throw SpecException::invalid(['The request is missing the "spec" field.']);
        }

        $expected = ($body->force ?? false) ? null : (isset($body->hash) ? (string) $body->hash : null);
        $hash = $this->specs->save($name, $body->spec, $expected);

        return $this->json(['name' => $name, 'hash' => $hash]);
    }

    public function destroy(string $name): JsonResponse
    {
        $this->specs->delete($name);

        return $this->json(['deleted' => $name]);
    }

    /**
     * Multipart: file (yaml/yml/json), name? (target file name), overwrite? (bool)
     */
    public function import(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            throw SpecException::invalid(['Choose a .yaml, .yml or .json file to import.']);
        }

        $original = $file->getClientOriginalName();
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (! in_array($extension, ['yaml', 'yml', 'json'], true)) {
            throw SpecException::invalid(['Only .yaml, .yml and .json files can be imported.']);
        }

        $spec = $this->serializer->parse((string) file_get_contents($file->getRealPath()), $extension === 'json' ? 'json' : 'yaml');

        $errors = $this->validator->validate($spec);
        if ($errors !== []) {
            throw SpecException::invalid($errors);
        }

        $target = (string) $request->input('name', '');
        $name = $this->normalizeName($target !== '' ? $target : pathinfo($original, PATHINFO_FILENAME), $extension === 'json' ? 'json' : 'yaml');

        $hash = $this->specs->create($name, $spec, $request->boolean('overwrite'));

        return $this->json(['name' => $name, 'hash' => $hash], 201);
    }

    public function export(Request $request, string $name): Response
    {
        $format = $request->query('format', SpecSerializer::formatFor($name)) === 'json' ? 'json' : 'yaml';
        $base = pathinfo($name, PATHINFO_FILENAME);

        $contents = $format === SpecSerializer::formatFor($name)
            ? $this->specs->contents($name)
            : $this->serializer->dump($this->specs->read($name)['spec'], $format);

        $filename = $base.'.'.$format;

        return response($contents, 200, [
            'Content-Type' => ($format === 'json' ? 'application/json' : 'application/yaml').'; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Body: { yaml: string } -> { spec, errors }
     */
    public function parse(Request $request): JsonResponse
    {
        $body = $this->body($request);
        $spec = $this->serializer->parse((string) ($body->yaml ?? ''), 'yaml');

        return $this->json(['spec' => $spec, 'errors' => $this->validator->validate($spec)]);
    }

    /**
     * Body: { spec: object } -> { yaml }
     */
    public function dump(Request $request): JsonResponse
    {
        $body = $this->body($request);

        return $this->json(['yaml' => $this->serializer->dump($body->spec ?? new stdClass, 'yaml')]);
    }

    private function body(Request $request): stdClass
    {
        $body = $this->serializer->decodeJson($request->getContent() ?: '{}');

        if (! $body instanceof stdClass) {
            throw SpecException::invalid(['The request body must be a JSON object.']);
        }

        return $body;
    }

    private function normalizeName(string $name, string $format): string
    {
        $name = trim($name);
        if (! preg_match('/\.(ya?ml|json)$/i', $name)) {
            $name = Str::slug($name) ?: 'api';
            $name .= '.'.($format === 'json' ? 'json' : 'yaml');
        }

        if (! SpecRepository::isValidName($name)) {
            throw SpecException::badName($name);
        }

        return $name;
    }

    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
