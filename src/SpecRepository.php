<?php

namespace OpenApiStudio;

use Illuminate\Filesystem\Filesystem;
use OpenApiStudio\Exceptions\SpecException;
use OpenApiStudio\Support\SpecSerializer;
use OpenApiStudio\Support\SpecValidator;
use stdClass;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Reads and writes spec files in one flat directory.
 *
 * File names are restricted to a safe pattern with no slashes, so a request
 * can never reach a file outside the directory.
 */
class SpecRepository
{
    private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*\.(ya?ml|json)$/i';

    public function __construct(
        private Filesystem $files,
        private SpecSerializer $serializer,
        private SpecValidator $validator,
        private string $directory,
        private int $keepBackups = 10,
    ) {}

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name) && ! str_contains($name, '..');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * @return list<array{name: string, format: string, title: ?string, version: ?string, size: int, updated_at: int, error: ?string}>
     */
    public function all(): array
    {
        if (! $this->files->isDirectory($this->directory)) {
            return [];
        }

        $specs = collect($this->files->files($this->directory))
            ->filter(fn (SplFileInfo $file) => self::isValidName($file->getFilename()))
            ->map(function (SplFileInfo $file) {
                $name = $file->getFilename();
                $summary = [
                    'name' => $name,
                    'format' => SpecSerializer::formatFor($name),
                    'title' => null,
                    'version' => null,
                    'size' => $file->getSize(),
                    'updated_at' => $file->getMTime(),
                    'error' => null,
                ];

                try {
                    $spec = $this->serializer->parse($file->getContents(), $summary['format']);
                    $summary['title'] = isset($spec->info->title) ? (string) $spec->info->title : null;
                    $summary['version'] = isset($spec->info->version) ? (string) $spec->info->version : null;
                } catch (SpecException $e) {
                    $summary['error'] = $e->errors()[0] ?? $e->getMessage();
                }

                return $summary;
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return $specs;
    }

    public function exists(string $name): bool
    {
        return self::isValidName($name) && $this->files->isFile($this->path($name));
    }

    public function contents(string $name): string
    {
        $this->ensureExists($name);

        return $this->files->get($this->path($name));
    }

    /**
     * @return array{spec: mixed, hash: string}
     */
    public function read(string $name): array
    {
        $contents = $this->contents($name);

        return [
            'spec' => $this->serializer->parse($contents, SpecSerializer::formatFor($name)),
            'hash' => sha1($contents),
        ];
    }

    /**
     * Create a new file. Fails if it already exists unless $overwrite is set.
     */
    public function create(string $name, mixed $spec, bool $overwrite = false): string
    {
        if (! $overwrite && $this->exists($name)) {
            throw SpecException::alreadyExists($name);
        }

        return $this->write($name, $spec);
    }

    /**
     * Save an existing file. When $expectedHash is given and the file on disk
     * no longer matches it, someone else changed the file, so refuse.
     */
    public function save(string $name, mixed $spec, ?string $expectedHash): string
    {
        $current = $this->contents($name);

        if ($expectedHash !== null && ! hash_equals(sha1($current), $expectedHash)) {
            throw SpecException::conflict($name);
        }

        return $this->write($name, $spec);
    }

    public function delete(string $name): void
    {
        $this->ensureExists($name);
        $this->backup($name);
        $this->files->delete($this->path($name));
    }

    public function skeleton(string $title, string $version, string $description = ''): stdClass
    {
        $info = (object) ['title' => $title, 'version' => $version];
        if ($description !== '') {
            $info->description = $description;
        }

        return (object) [
            'openapi' => '3.0.3',
            'info' => $info,
            'servers' => [(object) ['url' => '/api', 'description' => 'This application']],
            'paths' => new stdClass,
            'components' => (object) ['schemas' => new stdClass],
        ];
    }

    private function write(string $name, mixed $spec): string
    {
        $this->assertValidName($name);

        $errors = $this->validator->validate($spec);
        if ($errors !== []) {
            throw SpecException::invalid($errors);
        }

        $contents = $this->serializer->dump($spec, SpecSerializer::formatFor($name));

        $this->files->ensureDirectoryExists($this->directory);
        if ($this->exists($name)) {
            $this->backup($name);
        }
        // Normal file permissions (e.g. 0644); Laravel's default here would mark it executable.
        $this->files->replace($this->path($name), $contents, 0666 & ~umask());

        return sha1($contents);
    }

    private function backup(string $name): void
    {
        if ($this->keepBackups <= 0) {
            return;
        }

        $dir = $this->directory.DIRECTORY_SEPARATOR.'.backups';
        $this->files->ensureDirectoryExists($dir);

        $stamp = (new \DateTimeImmutable)->format('Ymd-His-u');
        $this->files->copy($this->path($name), $dir.DIRECTORY_SEPARATOR."{$name}.{$stamp}.bak");

        $old = collect($this->files->glob($dir.DIRECTORY_SEPARATOR.$this->globEscape($name).'.*.bak'))
            ->sort()
            ->values();

        $old->slice(0, max(0, $old->count() - $this->keepBackups))
            ->each(fn (string $file) => $this->files->delete($file));
    }

    private function ensureExists(string $name): void
    {
        $this->assertValidName($name);

        if (! $this->exists($name)) {
            throw SpecException::notFound($name);
        }
    }

    private function assertValidName(string $name): void
    {
        if (! self::isValidName($name)) {
            throw SpecException::badName($name);
        }
    }

    private function path(string $name): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }

    private function globEscape(string $value): string
    {
        return preg_replace('/([*?\[\]])/', '[$1]', $value);
    }
}
