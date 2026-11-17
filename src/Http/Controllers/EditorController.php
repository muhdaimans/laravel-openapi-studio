<?php

namespace OpenApiStudio\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use OpenApiStudio\SpecRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EditorController extends Controller
{
    private const ASSETS = [
        'app.js' => 'application/javascript',
        'app.css' => 'text/css',
        'alpine.min.js' => 'application/javascript',
        'sortable.min.js' => 'application/javascript',
    ];

    public function index(SpecRepository $specs): View
    {
        $assetVersion = substr(sha1(implode('|', array_map(
            fn (string $file) => (string) @filemtime($this->assetPath($file)),
            array_keys(self::ASSETS),
        ))), 0, 10);

        return view('openapi-studio::editor', [
            'config' => [
                'baseUrl' => rtrim(route('openapi-studio.index'), '/'),
                'directory' => $specs->directory(),
            ],
            'assetVersion' => $assetVersion,
        ]);
    }

    public function asset(string $file): BinaryFileResponse
    {
        abort_unless(isset(self::ASSETS[$file]), 404);

        return response()->file($this->assetPath($file), [
            'Content-Type' => self::ASSETS[$file].'; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    private function assetPath(string $file): string
    {
        return __DIR__.'/../../../resources/dist/'.$file;
    }
}
