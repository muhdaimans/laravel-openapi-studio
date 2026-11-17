# Laravel OpenAPI Studio

A visual editor for OpenAPI 3 files (`.yaml` / `.json`) inside a Laravel app. Create, edit and delete API specs through forms and drag-and-drop instead of writing YAML by hand.

- **Local only.** The editor returns 404 in every environment except `local`, so staging and production never expose it.
- **Plain files.** Specs live in `docs/api/` of your project. Commit them like any other file. No database.
- **Safe saves.** Each save checks that nobody else changed the file since you opened it, validates the document, and keeps the last 10 versions in `docs/api/.backups/`.
- **Lossless.** Fields the forms don't show (examples, `x-` extensions, security, `allOf`, …) are kept as they are.
- **No build step.** The JavaScript ships with the package, so nothing is needed from npm or a CDN.

## Install

From a local folder (while developing the package):

```json
// composer.json of your Laravel app
"repositories": [
    { "type": "path", "url": "../packages/laravel-openapi-studio" }
]
```

```bash
composer require muhdaimans/laravel-openapi-studio:@dev --dev
```

Open `http://your-app.test/openapi-studio`.

Optionally publish the config:

```bash
php artisan vendor:publish --tag=openapi-studio-config
```

## What you can do

| Tab | |
|---|---|
| **Overview** | API title, version, description, servers, tags (drag to reorder) |
| **Endpoints** | Drag a `GET`/`POST`/`PUT`/`PATCH`/`DELETE` chip into the list (or click it) to add an endpoint. Edit the path, summary, tags, parameters, request body and responses. Drag to reorder, duplicate or remove. |
| **Models** | Reusable data shapes (`components.schemas`) with fields, types, formats, required and nullable. Use them as a body or response shape: "User", or "List of User". |
| **YAML** | The whole document as YAML. Edit it directly; changes apply when you switch tabs or save. |

Sidebar: **New spec**, **Import** (upload a `.yaml`/`.yml`/`.json` file), and the list of files. Toolbar: **Export** (download as YAML or JSON), **Delete**, **Save** (or Ctrl/Cmd+S).

## Config (`config/openapi-studio.php`)

| Key | Default | |
|---|---|---|
| `enabled` | `env('OPENAPI_STUDIO_ENABLED', true)` | Master switch |
| `environments` | `['local']` | Environments where the editor is reachable. Everything else gets 404. |
| `directory` | `base_path('docs/api')` | Where spec files are read and written |
| `backups` | `10` | Backups kept per file (`0` turns them off) |
| `route.prefix` | `openapi-studio` | URL prefix |
| `route.middleware` | `['web']` | Middleware for the editor routes |

## Limits

- OpenAPI **3.x** only. Swagger 2.0 files are rejected on import; convert them first.
- Validation checks the document's structure (version, title, paths, models) rather than every rule in the OpenAPI spec.
- Saving rewrites the file, so YAML comments and custom formatting are not kept.
- A request body or response with several content types: the form edits the first one and keeps the others unchanged.

## Development

```bash
composer install
vendor/bin/phpunit            # tests
vendor/bin/testbench serve    # try the editor at http://127.0.0.1:8000/openapi-studio
```
