<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>OpenAPI Studio</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="{{ route('openapi-studio.asset', 'app.css') }}?v={{ $assetVersion }}">
    <script src="{{ route('openapi-studio.asset', 'sortable.min.js') }}?v={{ $assetVersion }}"></script>
    <script src="{{ route('openapi-studio.asset', 'app.js') }}?v={{ $assetVersion }}"></script>
    <script defer src="{{ route('openapi-studio.asset', 'alpine.min.js') }}?v={{ $assetVersion }}"></script>
</head>
<body x-data="openApiStudio(@js($config))" x-cloak>

{{-- ============ Sidebar: files ============ --}}
<aside class="sidebar">
    <div class="brand">
        <span class="brand-name">OpenAPI Studio</span>
        <span class="badge-local">local only</span>
    </div>

    <div class="sidebar-actions">
        <button class="btn btn-primary" @click="newSpec.open = true">New spec</button>
        <label class="btn">
            Import
            <input type="file" accept=".yaml,.yml,.json" hidden @change="importFile($event)">
        </label>
    </div>

    <nav class="file-list">
        <template x-for="s in specs" :key="s.name">
            <button class="file" :class="{ 'is-active': s.name === current }" @click="open(s.name)">
                <span class="file-title" x-text="s.title || s.name"></span>
                <span class="file-meta">
                    <span x-text="s.name"></span>
                    <template x-if="s.version"><span x-text="'v' + s.version"></span></template>
                </span>
                <template x-if="s.error"><span class="file-error" x-text="s.error"></span></template>
            </button>
        </template>
        <p class="muted small" x-show="!specs.length">No spec files yet. Create one or import an existing .yaml / .json file.</p>
    </nav>

    <p class="sidebar-foot muted small">Files are saved in <code x-text="directory"></code></p>
</aside>

{{-- ============ Main ============ --}}
<main class="main">

    <template x-if="!model">
        <div class="empty">
            <h1>Edit your API docs without writing YAML</h1>
            <p class="muted">Pick a file on the left, create a new spec, or import an existing OpenAPI 3 file.</p>
        </div>
    </template>

    <template x-if="model">
        <div>
            {{-- Toolbar --}}
            <header class="toolbar">
                <div class="toolbar-title">
                    <h1 x-text="model.info.title || current"></h1>
                    <span class="muted small" x-text="current"></span>
                    <span class="dirty-dot" x-show="dirty" title="Unsaved changes">Unsaved</span>
                </div>
                <div class="toolbar-actions">
                    <div class="menu" x-data="{ o: false }" @click.outside="o = false">
                        <button class="btn" @click="o = !o" :title="dirty ? 'Exports the last saved version' : ''">Export ▾</button>
                        <div class="menu-pop" x-show="o" x-transition.opacity>
                            <a :href="exportUrl('yaml')" @click="o = false">Download YAML</a>
                            <a :href="exportUrl('json')" @click="o = false">Download JSON</a>
                            <p class="muted small" x-show="dirty">Save first to include your latest changes.</p>
                        </div>
                    </div>
                    <button class="btn btn-danger-ghost" @click="removeSpec()">Delete</button>
                    <button class="btn btn-primary" @click="save()" :disabled="saving" x-text="saving ? 'Saving…' : 'Save'"></button>
                </div>
            </header>

            {{-- Conflict & problems --}}
            <div class="alert alert-warn" x-show="conflict">
                <strong>This file changed on disk after you opened it</strong> (another tab, a teammate, or a Git pull).
                <div class="alert-actions">
                    <button class="btn" @click="reloadFromDisk()">Reload from disk</button>
                    <button class="btn btn-danger-ghost" @click="save(true)">Overwrite with my version</button>
                </div>
            </div>
            <div class="alert alert-error" x-show="problems.length">
                <strong>Can't save yet:</strong>
                <ul><template x-for="p in problems"><li x-text="p"></li></template></ul>
            </div>

            {{-- Tabs --}}
            <div class="tabs" role="tablist">
                <button :class="{ on: tab === 'overview' }" @click="setTab('overview')">Overview</button>
                <button :class="{ on: tab === 'endpoints' }" @click="setTab('endpoints')">Endpoints <span class="count" x-text="model.endpoints.length"></span></button>
                <button :class="{ on: tab === 'schemas' }" @click="setTab('schemas')">Models <span class="count" x-text="model.schemas.length"></span></button>
                <button :class="{ on: tab === 'yaml' }" @click="setTab('yaml')">YAML</button>
            </div>

            {{-- ===== Overview ===== --}}
            <section x-show="tab === 'overview'" class="panel">
                <div class="grid-2">
                    <label class="field"><span>API title</span><input type="text" x-model="model.info.title"></label>
                    <label class="field"><span>Version</span><input type="text" x-model="model.info.version" placeholder="1.0.0"></label>
                </div>
                <label class="field"><span>Description</span><textarea rows="4" x-model="model.info.description" placeholder="What this API is for, how to authenticate, …"></textarea></label>

                <h3>Servers <span class="muted small">Base URLs the API is served from</span></h3>
                <div class="rows" x-init="sortable($el, () => model.servers)">
                    <template x-for="s in model.servers" :key="s.id">
                        <div class="row">
                            <span class="drag" title="Drag to reorder">⋮⋮</span>
                            <input type="text" x-model="s.url" placeholder="https://api.example.com/v1" class="grow">
                            <input type="text" x-model="s.description" placeholder="Description">
                            <button class="icon-btn" title="Remove" @click="model.servers.splice(model.servers.indexOf(s), 1)">✕</button>
                        </div>
                    </template>
                </div>
                <button class="btn btn-small" @click="addServer()">+ Add server</button>

                <h3>Tags <span class="muted small">Groups shown in the docs, e.g. Users, Orders</span></h3>
                <div class="rows" x-init="sortable($el, () => model.tags)">
                    <template x-for="t in model.tags" :key="t.id">
                        <div class="row">
                            <span class="drag" title="Drag to reorder">⋮⋮</span>
                            <input type="text" x-model="t.name" placeholder="Name">
                            <input type="text" x-model="t.description" placeholder="Description" class="grow">
                            <button class="icon-btn" title="Remove" @click="model.tags.splice(model.tags.indexOf(t), 1)">✕</button>
                        </div>
                    </template>
                </div>
                <button class="btn btn-small" @click="addTag()">+ Add tag</button>
            </section>

            {{-- ===== Endpoints ===== --}}
            <section x-show="tab === 'endpoints'" class="panel panel-endpoints">
                <div class="palette-bar">
                    <span class="muted small">Drag a method into the list, or click it:</span>
                    <div class="palette" x-init="palette($el)" @click="const m = $event.target.closest('[data-method]'); if (m) addEndpoint(m.dataset.method)">
                        {{-- Static markup on purpose: SortableJS clones these chips while dragging, and clones of Alpine-bound elements throw errors. --}}
                        @foreach (['get', 'post', 'put', 'patch', 'delete'] as $method)
                            <span class="chip method m-{{ $method }}" data-method="{{ $method }}">{{ strtoupper($method) }}</span>
                        @endforeach
                    </div>
                    <input type="search" class="filter" x-model="filter" placeholder="Filter endpoints…">
                </div>

                <div class="endpoint-list" x-init="sortable($el, () => model.endpoints, { group: 'endpoints', handle: '.ep-drag', draggable: '.endpoint' })">
                    <div class="dropzone-hint muted" x-show="!model.endpoints.length">No endpoints yet. Drag a method chip here, or click one above.</div>
                    <template x-for="e in model.endpoints" :key="e.id">
                        <article class="endpoint" :id="'ep-' + e.id" x-show="visible(e)" :class="{ 'is-open': e.open, 'is-deprecated': e.deprecated }">
                            <div class="endpoint-head" @click="e.open = !e.open">
                                <span class="drag ep-drag" title="Drag to reorder" @click.stop>⋮⋮</span>
                                <span class="method" :class="'m-' + e.method" x-text="e.method.toUpperCase()"></span>
                                <span class="path" x-text="e.path"></span>
                                <span class="summary muted" x-text="e.summary"></span>
                                <span class="tag-pill" x-show="e.tags" x-text="e.tags"></span>
                                <span class="chev" x-text="e.open ? '▾' : '▸'"></span>
                            </div>

                            <template x-if="e.open">
                                <div class="endpoint-body">
                                    <div class="grid-ep">
                                        <label class="field"><span>Method</span>
                                            <select @change="e.method = $event.target.value">
                                                <template x-for="m in allMethods" :key="m"><option :value="m" :selected="m === e.method" x-text="m.toUpperCase()"></option></template>
                                            </select>
                                        </label>
                                        <label class="field"><span>Path</span><input type="text" x-model="e.path" placeholder="/users/{id}" class="mono"></label>
                                        <label class="field"><span>Summary</span><input type="text" x-model="e.summary" placeholder="Get a user"></label>
                                    </div>
                                    <label class="field"><span>Description</span><textarea rows="2" x-model="e.description"></textarea></label>
                                    <div class="grid-2">
                                        <div class="field"><span>Tags</span>
                                            <div class="tag-picker">
                                                <template x-for="t in tagNames()" :key="t">
                                                    <button type="button" class="chip" :class="{ on: hasTag(e, t) }" @click="toggleTag(e, t)" x-text="t"></button>
                                                </template>
                                                <input type="text" x-model="e.tags" placeholder="Comma separated" class="grow">
                                            </div>
                                        </div>
                                        <div class="grid-2">
                                            <label class="field"><span>Operation ID</span><input type="text" x-model="e.operationId" placeholder="getUser" class="mono"></label>
                                            <label class="check"><input type="checkbox" x-model="e.deprecated"> Deprecated</label>
                                        </div>
                                    </div>

                                    {{-- Parameters --}}
                                    <h4>Parameters</h4>
                                    <div class="rows" x-init="sortable($el, () => e.parameters)">
                                        <template x-for="p in e.parameters" :key="p.id">
                                            <div class="row">
                                                <span class="drag" title="Drag to reorder">⋮⋮</span>
                                                <template x-if="p.ref"><span class="muted grow">Shared parameter <code x-text="p.ref"></code></span></template>
                                                <template x-if="!p.ref">
                                                    <div class="row-fields">
                                                        <input type="text" x-model="p.name" placeholder="name" class="mono">
                                                        <select @change="p.in = $event.target.value">
                                                            <template x-for="l in paramLocations" :key="l"><option :value="l" :selected="l === p.in" x-text="'in ' + l"></option></template>
                                                        </select>
                                                        <select @change="p.type = $event.target.value">
                                                            <template x-for="t in paramTypes" :key="t"><option :value="t" :selected="t === p.type" x-text="t"></option></template>
                                                        </select>
                                                        <label class="check"><input type="checkbox" :checked="p.required || p.in === 'path'" :disabled="p.in === 'path'" @change="p.required = $event.target.checked"> required</label>
                                                        <input type="text" x-model="p.description" placeholder="Description" class="grow">
                                                    </div>
                                                </template>
                                                <button class="icon-btn" title="Remove" @click="e.parameters.splice(e.parameters.indexOf(p), 1)">✕</button>
                                            </div>
                                        </template>
                                    </div>
                                    <button class="btn btn-small" @click="addParam(e)">+ Add parameter</button>

                                    {{-- Request body --}}
                                    <h4>Request body</h4>
                                    <label class="check"><input type="checkbox" x-model="e.body.enabled"> This endpoint accepts a body</label>
                                    <template x-if="e.body.enabled && e.body.ref">
                                        <p class="muted">Uses shared request body <code x-text="e.body.ref"></code></p>
                                    </template>
                                    <template x-if="e.body.enabled && !e.body.ref">
                                        <div class="slot">
                                            <div class="row-fields">
                                                <label class="field"><span>Content type</span><input type="text" x-model="e.body.contentType" list="content-types" class="mono"></label>
                                                <div class="field"><span>Body shape</span>
                                                    @include('openapi-studio::partials.kind-select', ['target' => 'e.body.schema', 'withNone' => 'true'])
                                                </div>
                                                <label class="check"><input type="checkbox" x-model="e.body.required"> required</label>
                                            </div>
                                            <template x-if="e.body.schema.kind === 'raw'">
                                                <textarea class="mono code" rows="6" x-model="e.body.schema.raw" spellcheck="false"></textarea>
                                            </template>
                                        </div>
                                    </template>

                                    {{-- Responses --}}
                                    <h4>Responses</h4>
                                    <div class="rows" x-init="sortable($el, () => e.responses)">
                                        <template x-for="r in e.responses" :key="r.id">
                                            <div class="row row-top">
                                                <span class="drag" title="Drag to reorder">⋮⋮</span>
                                                <input type="text" x-model="r.code" class="mono code-input" :class="'status-' + (r.code || '')[0]" placeholder="200">
                                                <template x-if="r.ref"><span class="muted grow">Shared response <code x-text="r.ref"></code></span></template>
                                                <template x-if="!r.ref">
                                                    <div class="grow">
                                                        <div class="row-fields">
                                                            <input type="text" x-model="r.description" placeholder="Description, e.g. User found" class="grow">
                                                            @include('openapi-studio::partials.kind-select', ['target' => 'r.schema', 'withNone' => 'true'])
                                                            <input type="text" x-model="r.contentType" list="content-types" class="mono" x-show="r.schema.kind !== 'none'" title="Content type">
                                                        </div>
                                                        <template x-if="r.schema.kind === 'raw'">
                                                            <textarea class="mono code" rows="5" x-model="r.schema.raw" spellcheck="false"></textarea>
                                                        </template>
                                                    </div>
                                                </template>
                                                <button class="icon-btn" title="Remove" @click="e.responses.splice(e.responses.indexOf(r), 1)">✕</button>
                                            </div>
                                        </template>
                                    </div>
                                    <button class="btn btn-small" @click="addResponse(e)">+ Add response</button>

                                    <div class="endpoint-foot">
                                        <button class="btn btn-small" @click="duplicateEndpoint(e)">Duplicate</button>
                                        <button class="btn btn-small btn-danger-ghost" @click="removeEndpoint(e)">Remove endpoint</button>
                                    </div>
                                </div>
                            </template>
                        </article>
                    </template>
                </div>
            </section>

            {{-- ===== Models (components.schemas) ===== --}}
            <section x-show="tab === 'schemas'" class="panel panel-schemas">
                <p class="muted small">Models describe the data your API sends and receives (for example a User). Use them as a body or response shape in Endpoints.</p>
                <div class="endpoint-list" x-init="sortable($el, () => model.schemas, { handle: '.sc-drag' })">
                    <template x-for="m in model.schemas" :key="m.id">
                        <article class="endpoint" :id="'sc-' + m.id" :class="{ 'is-open': m.open }">
                            <div class="endpoint-head" @click="m.open = !m.open">
                                <span class="drag sc-drag" title="Drag to reorder" @click.stop>⋮⋮</span>
                                <span class="model-icon">{ }</span>
                                <span class="path" x-text="m.name"></span>
                                <span class="summary muted" x-text="m.mode === 'object' ? m.properties.length + ' fields' : 'custom JSON'"></span>
                                <span class="chev" x-text="m.open ? '▾' : '▸'"></span>
                            </div>
                            <template x-if="m.open">
                                <div class="endpoint-body">
                                    <div class="grid-2">
                                        <label class="field"><span>Model name</span><input type="text" :value="m.name" @change="renameSchema(m, $event.target.value)" class="mono"></label>
                                        <label class="field" x-show="m.mode === 'object'"><span>Description</span><input type="text" x-model="m.description"></label>
                                    </div>

                                    <template x-if="m.mode === 'object'">
                                        <div>
                                            <h4>Fields</h4>
                                            <div class="rows" x-init="sortable($el, () => m.properties)">
                                                <template x-for="p in m.properties" :key="p.id">
                                                    <div class="row row-top">
                                                        <span class="drag" title="Drag to reorder">⋮⋮</span>
                                                        <div class="grow">
                                                            <div class="row-fields">
                                                                <input type="text" x-model="p.name" placeholder="field_name" class="mono">
                                                                @include('openapi-studio::partials.kind-select', ['target' => 'p', 'withNone' => 'false'])
                                                                <input type="text" x-model="p.format" placeholder="format (email, date-time…)" list="formats" class="narrow">
                                                                <label class="check"><input type="checkbox" x-model="p.required"> required</label>
                                                                <label class="check"><input type="checkbox" x-model="p.nullable"> nullable</label>
                                                                <input type="text" x-model="p.description" placeholder="Description" class="grow">
                                                            </div>
                                                            <template x-if="p.kind === 'raw'">
                                                                <textarea class="mono code" rows="4" x-model="p.raw" spellcheck="false"></textarea>
                                                            </template>
                                                        </div>
                                                        <button class="icon-btn" title="Remove" @click="m.properties.splice(m.properties.indexOf(p), 1)">✕</button>
                                                    </div>
                                                </template>
                                            </div>
                                            <button class="btn btn-small" @click="addProperty(m)">+ Add field</button>
                                        </div>
                                    </template>
                                    <template x-if="m.mode === 'raw'">
                                        <div>
                                            <p class="muted small">This model uses features the form can't show, so it is edited as JSON Schema.</p>
                                            <textarea class="mono code" rows="12" x-model="m.raw" spellcheck="false"></textarea>
                                        </div>
                                    </template>

                                    <div class="endpoint-foot">
                                        <button class="btn btn-small" @click="toggleSchemaMode(m)" x-text="m.mode === 'object' ? 'Edit as JSON' : 'Back to form'"></button>
                                        <button class="btn btn-small btn-danger-ghost" @click="removeSchema(m)">Remove model</button>
                                    </div>
                                </div>
                            </template>
                        </article>
                    </template>
                </div>
                <button class="btn" @click="addSchema()">+ Add model</button>
            </section>

            {{-- ===== Raw YAML ===== --}}
            <section x-show="tab === 'yaml'" class="panel">
                <p class="muted small">The full document as YAML. You can edit it directly; changes apply when you switch tabs or save.</p>
                <div class="alert alert-error" x-show="yamlErrors.length">
                    <ul><template x-for="p in yamlErrors"><li x-text="p"></li></template></ul>
                </div>
                <textarea class="mono code yaml" x-model="yamlText" spellcheck="false"></textarea>
                <div class="endpoint-foot">
                    <button class="btn" @click="applyYaml()" :disabled="yamlText === yamlLoaded">Apply YAML to the form</button>
                </div>
            </section>
        </div>
    </template>
</main>

{{-- ============ New spec dialog ============ --}}
<div class="modal" x-show="newSpec.open" x-transition.opacity @keydown.escape.window="newSpec.open = false">
    <form class="modal-card" @submit.prevent="createSpec()" @click.outside="newSpec.open = false">
        <h2>New API spec</h2>
        <label class="field"><span>API title</span><input type="text" x-model="newSpec.title" placeholder="Orders API" x-effect="newSpec.open && $nextTick(() => $el.focus())"></label>
        <div class="grid-2">
            <label class="field"><span>Version</span><input type="text" x-model="newSpec.version"></label>
            <label class="field"><span>Format</span>
                <select x-model="newSpec.format"><option value="yaml">YAML</option><option value="json">JSON</option></select>
            </label>
        </div>
        <label class="field"><span>File name <span class="muted">(optional)</span></span><input type="text" x-model="newSpec.name" placeholder="orders-api.yaml" class="mono"></label>
        <p class="alert alert-error" x-show="newSpec.error" x-text="newSpec.error"></p>
        <div class="modal-actions">
            <button type="button" class="btn" @click="newSpec.open = false">Cancel</button>
            <button type="submit" class="btn btn-primary">Create</button>
        </div>
    </form>
</div>

<div class="toast" x-show="toast" x-transition :class="toast && 'toast-' + toast.type" x-text="toast && toast.text"></div>

<datalist id="content-types">
    <option value="application/json"><option value="multipart/form-data"><option value="application/x-www-form-urlencoded"><option value="text/plain"><option value="application/octet-stream">
</datalist>
<datalist id="formats">
    <option value="email"><option value="date"><option value="date-time"><option value="uuid"><option value="uri"><option value="password"><option value="int64"><option value="float"><option value="binary">
</datalist>
</body>
</html>
