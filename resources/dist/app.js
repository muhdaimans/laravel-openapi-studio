/*
 * OpenAPI Studio — front end.
 *
 * The OpenAPI document is turned into a flat, form-friendly "model" when a
 * file opens (toModel) and turned back into OpenAPI when saving (toSpec).
 * Every model item keeps the original object in `_orig`, so fields the forms
 * don't show (examples, x-extensions, security, ...) survive a save untouched.
 */
(() => {
  'use strict';

  const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace'];
  const PRIMITIVES = ['string', 'integer', 'number', 'boolean', 'object'];
  const SCHEMA_PREFIX = '#/components/schemas/';

  let lastId = 0;
  const nid = () => ++lastId;
  const snap = (m) => JSON.stringify(m, (k, v) => (k === 'open' ? undefined : v));
  const clone = (v) => (v === undefined ? undefined : JSON.parse(JSON.stringify(v)));
  const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
  const setOrDelete = (obj, key, value) => {
    if (value === '' || value === undefined || value === null) delete obj[key];
    else obj[key] = value;
  };
  const refToName = (ref) => (typeof ref === 'string' && ref.startsWith(SCHEMA_PREFIX) ? decodeURIComponent(ref.slice(SCHEMA_PREFIX.length)) : null);

  // ---- Type picker ---------------------------------------------------------
  // A schema slot is edited through one dropdown "kind":
  //   none | string | integer | number | boolean | object | ref:Name
  //   | array:<string|...> | array:ref:Name | raw (free JSON)

  function kindOf(schema) {
    if (schema === undefined || schema === null) return 'none';
    if (!isObj(schema)) return 'raw';
    const keys = Object.keys(schema);
    if (keys.length === 1 && keys[0] === '$ref' && refToName(schema.$ref) !== null) return 'ref:' + refToName(schema.$ref);
    if (schema.type === 'array' && keys.every((k) => k === 'type' || k === 'items')) {
      const inner = kindOf(schema.items);
      return inner.startsWith('ref:') || PRIMITIVES.includes(inner) ? 'array:' + inner : 'raw';
    }
    if (PRIMITIVES.includes(schema.type) && keys.length === 1) return schema.type;
    return 'raw';
  }

  function schemaFromKind(kind, raw) {
    if (kind === 'none') return undefined;
    if (kind === 'raw') return JSON.parse(raw && raw.trim() ? raw : '{}');
    if (kind.startsWith('array:')) return { type: 'array', items: schemaFromKind(kind.slice(6)) };
    if (kind.startsWith('ref:')) return { $ref: SCHEMA_PREFIX + encodeURIComponent(kind.slice(4)) };
    return { type: kind };
  }

  function picker(schema) {
    const kind = kindOf(schema);
    return { kind, raw: kind === 'raw' ? JSON.stringify(schema, null, 2) : '' };
  }

  function kindLabel(kind) {
    if (kind === 'none') return 'No body';
    if (kind === 'raw') return 'Custom (JSON)';
    if (kind.startsWith('array:')) return 'List of ' + kindLabel(kind.slice(6));
    if (kind.startsWith('ref:')) return kind.slice(4);
    return kind;
  }

  // ---- OpenAPI -> model ----------------------------------------------------

  function mediaPicker(content) {
    const types = Object.keys(content || {});
    const contentType = types[0] || 'application/json';
    return { contentType, origContentType: types[0] || null, schema: picker(content?.[contentType]?.schema) };
  }

  function endpointModel(path, method, op, pathExtras) {
    const o = clone(op);

    const parameters = (o.parameters || []).map((p) => (p.$ref
      ? { id: nid(), ref: p.$ref, _orig: p }
      : { id: nid(), name: p.name ?? '', in: p.in ?? 'query', required: !!p.required, description: p.description ?? '', type: p.schema?.type ?? 'string', _orig: p }));

    let body = { enabled: false, required: false, description: '', contentType: 'application/json', origContentType: null, schema: picker(undefined), ref: null, _orig: null };
    if (o.requestBody) {
      const rb = o.requestBody;
      body = rb.$ref
        ? { ...body, enabled: true, ref: rb.$ref, _orig: rb }
        : { ...body, enabled: true, required: !!rb.required, description: rb.description ?? '', ...mediaPicker(rb.content), _orig: rb };
    }

    const responses = Object.entries(o.responses || {}).map(([code, r]) => (r && r.$ref
      ? { id: nid(), code: String(code), ref: r.$ref, _orig: r }
      : { id: nid(), code: String(code), description: r?.description ?? '', ...mediaPicker(r?.content), _orig: r || {} }));

    return {
      id: nid(), open: false, method, path,
      summary: o.summary ?? '', description: o.description ?? '', operationId: o.operationId ?? '',
      tags: (o.tags || []).join(', '), deprecated: !!o.deprecated,
      parameters, body, responses, _orig: o, _pathExtras: pathExtras,
    };
  }

  function propertyModel(name, schema, required) {
    const rest = isObj(schema) ? clone(schema) : {};
    const description = rest.description ?? '';
    const format = rest.format ?? '';
    const nullable = rest.nullable === true;
    delete rest.description;
    delete rest.format;
    if (nullable) delete rest.nullable;
    return { id: nid(), name, required, nullable, description, format, ...picker(rest) };
  }

  function schemaModel(name, schema) {
    const s = clone(schema);
    const structured = isObj(s) && (s.type === 'object' || (!s.type && isObj(s.properties)))
      && (s.properties === undefined || isObj(s.properties))
      && !s.$ref && !s.allOf && !s.oneOf && !s.anyOf;

    if (!structured) {
      return { id: nid(), name, open: false, mode: 'raw', raw: JSON.stringify(s, null, 2), description: '', properties: [], _orig: s };
    }

    const required = Array.isArray(s.required) ? s.required : [];
    return {
      id: nid(), name, open: false, mode: 'object', raw: '', description: s.description ?? '',
      properties: Object.entries(s.properties || {}).map(([pn, ps]) => propertyModel(pn, ps, required.includes(pn))),
      _orig: s,
    };
  }

  function toModel(spec) {
    const s = isObj(spec) ? clone(spec) : {};
    const info = isObj(s.info) ? s.info : {};
    const model = {
      info: { title: info.title ?? '', version: String(info.version ?? ''), description: info.description ?? '' },
      servers: (s.servers || []).map((x) => ({ id: nid(), url: x.url ?? '', description: x.description ?? '', _orig: x })),
      tags: (s.tags || []).map((x) => ({ id: nid(), name: x.name ?? '', description: x.description ?? '', _orig: x })),
      endpoints: [],
      opaquePaths: {},
      schemas: Object.entries(s.components?.schemas || {}).map(([n, sc]) => schemaModel(n, sc)),
      _orig: s,
    };

    for (const [path, item] of Object.entries(s.paths || {})) {
      if (!isObj(item)) continue;
      const extras = {};
      const ops = [];
      for (const [key, value] of Object.entries(item)) {
        if (METHODS.includes(key) && isObj(value)) ops.push([key, value]);
        else extras[key] = value;
      }
      if (ops.length === 0) model.opaquePaths[path] = item;
      else ops.forEach(([method, op]) => model.endpoints.push(endpointModel(path, method, op, extras)));
    }

    return model;
  }

  // ---- model -> OpenAPI ----------------------------------------------------

  function buildMedia(slot, origContent, problems, where, keepEmpty = false) {
    const content = clone(origContent) || {};
    const media = (slot.origContentType && content[slot.origContentType]) || {};
    if (slot.origContentType) delete content[slot.origContentType];

    let schema;
    try {
      schema = schemaFromKind(slot.schema.kind, slot.schema.raw);
    } catch (e) {
      problems.push(where + ': the custom JSON schema is not valid JSON (' + e.message + ').');
    }
    if (schema === undefined) delete media.schema;
    else media.schema = schema;

    const type = (slot.contentType || 'application/json').trim();
    const result = keepEmpty || Object.keys(media).length ? { [type]: media } : {};
    return { ...result, ...content };
  }

  function endpointToOperation(e, problems) {
    const where = e.method.toUpperCase() + ' ' + e.path;
    const op = clone(e._orig) || {};

    setOrDelete(op, 'summary', e.summary.trim());
    setOrDelete(op, 'description', e.description.trim());
    setOrDelete(op, 'operationId', e.operationId.trim());
    const tags = e.tags.split(',').map((t) => t.trim()).filter(Boolean);
    if (tags.length) op.tags = tags; else delete op.tags;
    if (e.deprecated) op.deprecated = true; else delete op.deprecated;

    const params = e.parameters.map((p) => {
      if (p.ref) return clone(p._orig);
      const out = clone(p._orig) || {};
      if (!p.name.trim()) problems.push(where + ': a parameter has no name.');
      out.name = p.name.trim();
      out.in = p.in;
      if (p.in === 'path' || p.required) out.required = true; else delete out.required;
      setOrDelete(out, 'description', p.description.trim());
      const schema = isObj(out.schema) ? out.schema : {};
      if (schema.type !== p.type) delete schema.format;
      schema.type = p.type;
      out.schema = schema;
      return out;
    });
    if (params.length) op.parameters = params; else delete op.parameters;

    for (const name of (e.path.match(/\{([^}]+)\}/g) || []).map((m) => m.slice(1, -1))) {
      const declared = e.parameters.some((p) => !p.ref && p.in === 'path' && p.name.trim() === name)
        || e.parameters.some((p) => p.ref)
        || (e._pathExtras?.parameters || []).some((p) => p.in === 'path' && p.name === name);
      if (!declared) problems.push(where + ': path parameter {' + name + '} needs a matching parameter with location "path".');
    }

    if (!e.body.enabled) {
      delete op.requestBody;
    } else if (e.body.ref) {
      op.requestBody = clone(e.body._orig);
    } else {
      const rb = clone(e.body._orig) || {};
      // A request body must declare at least one content type, even without a schema.
      rb.content = buildMedia(e.body, rb.content, problems, where + ' request body', true);
      if (e.body.required) rb.required = true; else delete rb.required;
      setOrDelete(rb, 'description', e.body.description.trim());
      op.requestBody = rb;
    }

    const responses = {};
    for (const r of e.responses) {
      const code = r.code.trim();
      if (!/^([1-5]\d\d|[1-5]XX|default)$/.test(code)) problems.push(where + ': "' + code + '" is not a valid response code (use e.g. 200, 404, 4XX or default).');
      if (responses[code]) problems.push(where + ': response ' + code + ' appears twice.');
      if (r.ref) { responses[code] = clone(r._orig); continue; }
      const out = clone(r._orig) || {};
      out.description = r.description.trim() || 'Response';
      const content = buildMedia(r, out.content, problems, where + ' response ' + code);
      if (Object.keys(content).length) out.content = content; else delete out.content;
      responses[code] = out;
    }
    op.responses = Object.keys(responses).length ? responses : { 200: { description: 'OK' } };

    return op;
  }

  function schemaToObject(m, problems) {
    if (m.mode === 'raw') {
      try { return JSON.parse(m.raw); } catch (e) { problems.push('Model ' + m.name + ': the JSON is not valid (' + e.message + ').'); return {}; }
    }
    const out = clone(m._orig) || {};
    if (!out.type) out.type = 'object';
    setOrDelete(out, 'description', m.description.trim());
    const properties = {};
    const required = [];
    for (const p of m.properties) {
      const name = p.name.trim();
      if (!name) { problems.push('Model ' + m.name + ': a field has no name.'); continue; }
      if (properties[name]) problems.push('Model ' + m.name + ': field "' + name + '" appears twice.');
      let s;
      try { s = schemaFromKind(p.kind, p.raw) || {}; } catch (e) { problems.push('Model ' + m.name + ', field ' + name + ': custom JSON is not valid (' + e.message + ').'); s = {}; }
      if (p.format.trim()) s.format = p.format.trim();
      if (p.nullable) s.nullable = true;
      if (p.description.trim()) s.description = p.description.trim();
      properties[name] = s;
      if (p.required) required.push(name);
    }
    out.properties = properties;
    if (required.length) out.required = required; else delete out.required;
    return out;
  }

  function toSpec(model) {
    const problems = [];
    const spec = clone(model._orig) || {};

    if (!spec.openapi) spec.openapi = '3.0.3';
    spec.info = isObj(spec.info) ? spec.info : {};
    spec.info.title = model.info.title.trim();
    spec.info.version = model.info.version.trim();
    setOrDelete(spec.info, 'description', model.info.description.trim());
    if (!spec.info.title) problems.push('The API title is required (Overview tab).');
    if (!spec.info.version) problems.push('The API version is required (Overview tab).');

    if (model.servers.length) {
      spec.servers = model.servers.map((x) => {
        const out = clone(x._orig) || {};
        out.url = x.url.trim();
        setOrDelete(out, 'description', x.description.trim());
        if (!out.url) problems.push('A server has no URL (Overview tab).');
        return out;
      });
    } else delete spec.servers;

    if (model.tags.length) {
      spec.tags = model.tags.map((x) => {
        const out = clone(x._orig) || {};
        out.name = x.name.trim();
        setOrDelete(out, 'description', x.description.trim());
        if (!out.name) problems.push('A tag has no name (Overview tab).');
        return out;
      });
    } else delete spec.tags;

    const paths = {};
    for (const e of model.endpoints) {
      const path = e.path.trim();
      if (!path.startsWith('/')) problems.push('Endpoint "' + path + '" must start with "/".');
      if (!paths[path]) paths[path] = clone(e._pathExtras) || {};
      if (paths[path][e.method]) problems.push(e.method.toUpperCase() + ' ' + path + ' is defined twice.');
      paths[path][e.method] = endpointToOperation(e, problems);
    }
    for (const [path, item] of Object.entries(model.opaquePaths)) if (!paths[path]) paths[path] = clone(item);
    spec.paths = paths;

    const schemas = {};
    for (const m of model.schemas) {
      const name = m.name.trim();
      if (!/^[A-Za-z0-9._-]+$/.test(name)) problems.push('Model "' + name + '" needs a name using only letters, numbers, ".", "-" or "_".');
      if (schemas[name]) problems.push('Model "' + name + '" is defined twice.');
      schemas[name] = schemaToObject(m, problems);
    }
    if (model.schemas.length || isObj(spec.components?.schemas)) {
      spec.components = isObj(spec.components) ? spec.components : {};
      if (model.schemas.length) spec.components.schemas = schemas; else delete spec.components.schemas;
      if (!Object.keys(spec.components).length) delete spec.components;
    }

    return { spec, problems };
  }

  // ---- New items -----------------------------------------------------------

  const newEndpoint = (method) => endpointModel('/resource', method, {
    summary: '',
    ...(['post', 'put', 'patch'].includes(method) ? { requestBody: { required: true, content: { 'application/json': {} } } } : {}),
    responses: { [method === 'post' ? '201' : method === 'delete' ? '204' : '200']: { description: method === 'delete' ? 'Deleted' : 'Successful response' } },
  }, {});

  const newProperty = () => ({ id: nid(), name: '', required: false, nullable: false, description: '', format: '', kind: 'string', raw: '' });

  // ---- Alpine component ----------------------------------------------------

  window.openApiStudio = (config) => ({
    config,
    allMethods: METHODS,
    primitives: PRIMITIVES,
    paramLocations: ['query', 'path', 'header', 'cookie'],
    paramTypes: ['string', 'integer', 'number', 'boolean', 'array'],

    specs: [],
    directory: config.directory,
    current: null,
    format: 'yaml',
    hash: null,
    model: null,
    snapshot: '',
    tab: 'endpoints',
    filter: '',
    loading: false,
    saving: false,
    problems: [],
    conflict: false,
    toast: null,
    yamlText: '',
    yamlLoaded: '',
    yamlErrors: [],
    newSpec: { open: false, title: '', version: '1.0.0', name: '', format: 'yaml', error: '' },

    async init() {
      await this.refreshList();
      const fromHash = decodeURIComponent(location.hash.slice(1));
      if (fromHash && this.specs.some((s) => s.name === fromHash)) await this.open(fromHash);
      window.addEventListener('beforeunload', (ev) => { if (this.dirty) { ev.preventDefault(); ev.returnValue = ''; } });
      window.addEventListener('keydown', (ev) => {
        if ((ev.metaKey || ev.ctrlKey) && ev.key === 's') { ev.preventDefault(); if (this.model) this.save(); }
      });
    },

    get dirty() {
      if (!this.model) return false;
      return snap(this.model) !== this.snapshot || (this.tab === 'yaml' && this.yamlText !== this.yamlLoaded);
    },

    // -- HTTP --
    async api(method, path, body, isForm = false) {
      const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content };
      if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json';
      const res = await fetch(this.config.baseUrl + '/api/' + path, {
        method, headers, credentials: 'same-origin',
        body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
      });
      let data = {};
      try { data = await res.json(); } catch (_) { data = { message: 'Unexpected response (' + res.status + ').' }; }
      return { ok: res.ok, status: res.status, data };
    },

    notify(text, type = 'ok') {
      this.toast = { text, type };
      clearTimeout(this._toastTimer);
      this._toastTimer = setTimeout(() => { this.toast = null; }, type === 'error' ? 6000 : 2500);
    },

    // -- Files --
    async refreshList() {
      const r = await this.api('GET', 'specs');
      if (r.ok) { this.specs = r.data.specs; this.directory = r.data.directory; }
      else this.notify(r.data.message || 'Could not load the spec list.', 'error');
    },

    confirmDiscard() {
      return !this.dirty || confirm('You have unsaved changes. Discard them?');
    },

    async open(name, force = false) {
      if (!force && name !== this.current && !this.confirmDiscard()) return;
      this.loading = true;
      const r = await this.api('GET', 'specs/' + encodeURIComponent(name));
      this.loading = false;
      if (!r.ok) {
        this.notify(r.data.errors?.[0] || r.data.message || 'Could not open the file.', 'error');
        return;
      }
      this.load(r.data);
      history.replaceState(null, '', '#' + encodeURIComponent(name));
    },

    load(data) {
      this.current = data.name;
      this.format = data.format;
      this.hash = data.hash;
      this.model = toModel(data.spec);
      this.snapshot = snap(this.model);
      this.problems = [];
      this.conflict = false;
      this.yamlText = this.yamlLoaded = '';
      this.yamlErrors = [];
      if (this.tab === 'yaml') this.loadYaml();
    },

    async save(force = false) {
      if (this.tab === 'yaml' && this.yamlText !== this.yamlLoaded) {
        if (!(await this.applyYaml())) return;
      }
      const { spec, problems } = toSpec(this.model);
      this.problems = problems;
      if (problems.length) { this.notify('Fix the problems listed above before saving.', 'error'); return; }

      this.saving = true;
      const r = await this.api('PUT', 'specs/' + encodeURIComponent(this.current), { spec, hash: this.hash, force });
      this.saving = false;

      if (r.status === 409) { this.conflict = true; return; }
      if (!r.ok) {
        this.problems = r.data.errors?.length ? r.data.errors : [r.data.message];
        this.notify('Not saved.', 'error');
        return;
      }
      this.hash = r.data.hash;
      this.conflict = false;
      this.snapshot = snap(this.model);
      this.notify('Saved ' + this.current);
      this.refreshList();
    },

    async reloadFromDisk() {
      if (confirm('Reload the file from disk? Your unsaved changes will be lost.')) await this.open(this.current, true);
    },

    async createSpec() {
      const n = this.newSpec;
      n.error = '';
      if (!n.title.trim()) { n.error = 'Give the API a title.'; return; }
      const r = await this.api('POST', 'specs', { title: n.title, version: n.version, name: n.name, format: n.format });
      if (!r.ok) { n.error = r.data.errors?.[0] || r.data.message; return; }
      this.newSpec = { open: false, title: '', version: '1.0.0', name: '', format: 'yaml', error: '' };
      await this.refreshList();
      await this.open(r.data.name, true);
      this.tab = 'endpoints';
      this.notify('Created ' + r.data.name);
    },

    async removeSpec() {
      if (!confirm('Delete ' + this.current + '? A copy is kept in ' + this.directory + '/.backups.')) return;
      const r = await this.api('DELETE', 'specs/' + encodeURIComponent(this.current));
      if (!r.ok) { this.notify(r.data.message, 'error'); return; }
      this.notify('Deleted ' + this.current);
      this.current = null;
      this.model = null;
      history.replaceState(null, '', location.pathname);
      await this.refreshList();
    },

    async importFile(event) {
      const file = event.target.files[0];
      event.target.value = '';
      if (!file) return;
      if (!this.confirmDiscard()) return;

      const send = (overwrite) => {
        const form = new FormData();
        form.append('file', file);
        if (overwrite) form.append('overwrite', '1');
        return this.api('POST', 'import', form, true);
      };

      let r = await send(false);
      if (r.status === 409 && confirm(r.data.message + ' Replace it with the imported file?')) r = await send(true);
      if (!r.ok) {
        if (r.status !== 409) this.notify((r.data.errors || []).join(' ') || r.data.message, 'error');
        return;
      }
      await this.refreshList();
      await this.open(r.data.name, true);
      this.notify('Imported ' + r.data.name);
    },

    exportUrl(format) {
      return this.config.baseUrl + '/api/specs/' + encodeURIComponent(this.current) + '/export?format=' + format;
    },

    // -- YAML tab --
    async setTab(tab) {
      if (this.tab === 'yaml' && tab !== 'yaml' && this.yamlText !== this.yamlLoaded) {
        if (!(await this.applyYaml())) return;
      }
      this.tab = tab;
      if (tab === 'yaml') await this.loadYaml();
    },

    async loadYaml() {
      const { spec } = toSpec(this.model);
      const r = await this.api('POST', 'convert/dump', { spec });
      this.yamlText = this.yamlLoaded = r.ok ? r.data.yaml : '';
      this.yamlErrors = r.ok ? [] : [r.data.message];
    },

    async applyYaml() {
      const r = await this.api('POST', 'convert/parse', { yaml: this.yamlText });
      const errors = r.ok ? r.data.errors : (r.data.errors?.length ? r.data.errors : [r.data.message]);
      if (errors.length) { this.yamlErrors = errors; this.notify('The YAML has problems; fix them first.', 'error'); return false; }
      const keepSnapshot = this.snapshot;
      this.model = toModel(r.data.spec);
      this.snapshot = keepSnapshot;
      this.yamlLoaded = this.yamlText;
      this.yamlErrors = [];
      return true;
    },

    // -- Overview --
    addServer() { this.model.servers.push({ id: nid(), url: '', description: '', _orig: {} }); },
    addTag() { this.model.tags.push({ id: nid(), name: '', description: '', _orig: {} }); },

    // -- Endpoints --
    visible(e) {
      const q = this.filter.trim().toLowerCase();
      if (!q) return true;
      return (e.method + ' ' + e.path + ' ' + e.summary + ' ' + e.tags).toLowerCase().includes(q);
    },
    addEndpoint(method, index = this.model.endpoints.length) {
      const e = newEndpoint(method);
      e.open = true;
      this.model.endpoints.splice(index, 0, e);
      this.$nextTick(() => document.getElementById('ep-' + e.id)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    },
    duplicateEndpoint(e) {
      const copy = endpointModel(e.path, e.method, endpointToOperation(e, []), clone(e._pathExtras));
      copy.open = true;
      copy.operationId = '';
      this.model.endpoints.splice(this.model.endpoints.indexOf(e) + 1, 0, copy);
    },
    removeEndpoint(e) {
      if (confirm('Remove ' + e.method.toUpperCase() + ' ' + e.path + '?')) this.model.endpoints.splice(this.model.endpoints.indexOf(e), 1);
    },
    addParam(e) {
      const inPath = (e.path.match(/\{([^}]+)\}/g) || []).map((m) => m.slice(1, -1)).find((n) => !e.parameters.some((p) => p.name === n));
      e.parameters.push({ id: nid(), name: inPath || '', in: inPath ? 'path' : 'query', required: !!inPath, description: '', type: 'string', _orig: {} });
    },
    addResponse(e) {
      const used = e.responses.map((r) => r.code);
      const code = ['200', '201', '204', '400', '401', '403', '404', '422', '500'].find((c) => !used.includes(c)) || '';
      e.responses.push({ id: nid(), code, description: '', contentType: 'application/json', origContentType: null, schema: picker(undefined), _orig: {} });
    },
    tagNames() { return this.model.tags.map((t) => t.name).filter(Boolean); },
    hasTag(e, name) { return e.tags.split(',').map((t) => t.trim()).includes(name); },
    toggleTag(e, name) {
      const tags = e.tags.split(',').map((t) => t.trim()).filter(Boolean);
      const i = tags.indexOf(name);
      if (i >= 0) tags.splice(i, 1); else tags.push(name);
      e.tags = tags.join(', ');
    },

    // -- Models --
    addSchema() {
      let name = 'NewModel';
      for (let i = 2; this.model.schemas.some((s) => s.name === name); i++) name = 'NewModel' + i;
      const m = { id: nid(), name, open: true, mode: 'object', raw: '', description: '', properties: [{ ...newProperty(), name: 'id', kind: 'integer', required: true }], _orig: { type: 'object' } };
      this.model.schemas.push(m);
      this.$nextTick(() => document.getElementById('sc-' + m.id)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    },
    removeSchema(m) {
      const used = JSON.stringify(toSpec(this.model).spec.paths).includes(JSON.stringify(SCHEMA_PREFIX + encodeURIComponent(m.name)));
      if (confirm('Remove model ' + m.name + '?' + (used ? ' Some endpoints still use it.' : ''))) this.model.schemas.splice(this.model.schemas.indexOf(m), 1);
    },
    renameSchema(m, newName) {
      const oldName = m.name;
      newName = newName.trim();
      if (!newName || newName === oldName) return;
      const swap = (k) => k.replace('ref:' + oldName, 'ref:' + newName);
      const fix = (p) => { if (p.kind === 'ref:' + oldName || p.kind === 'array:ref:' + oldName) p.kind = swap(p.kind); };
      for (const e of this.model.endpoints) {
        fix(e.body.schema);
        e.responses.forEach((r) => r.schema && fix(r.schema));
      }
      for (const s of this.model.schemas) s.properties.forEach(fix);
      m.name = newName;
    },
    addProperty(m) { m.properties.push(newProperty()); },
    toggleSchemaMode(m) {
      const problems = [];
      if (m.mode === 'object') {
        m.raw = JSON.stringify(schemaToObject(m, problems), null, 2);
        m.mode = 'raw';
      } else {
        let parsed;
        try { parsed = JSON.parse(m.raw); } catch (e) { this.notify('Fix the JSON first: ' + e.message, 'error'); return; }
        const next = schemaModel(m.name, parsed);
        if (next.mode !== 'object') { this.notify('This model uses features the form cannot show (allOf, oneOf, ...). Keep editing it as JSON.', 'error'); return; }
        Object.assign(m, { mode: 'object', description: next.description, properties: next.properties, _orig: next._orig });
      }
    },

    // -- Type picker --
    kindOptions(withNone, current) {
      const models = this.model.schemas.map((s) => s.name).filter(Boolean);
      const groups = [];
      if (withNone) groups.push({ label: '', options: ['none'] });
      groups.push({ label: 'Basic', options: PRIMITIVES });
      if (models.length) groups.push({ label: 'Models', options: models.map((n) => 'ref:' + n) });
      groups.push({ label: 'Lists', options: [...PRIMITIVES.filter((p) => p !== 'object'), ...models.map((n) => 'ref:' + n)].map((k) => 'array:' + k) });
      groups.push({ label: 'Advanced', options: ['raw'] });
      const all = groups.flatMap((g) => g.options);
      if (current && !all.includes(current)) groups.push({ label: 'Missing model', options: [current] });
      return groups;
    },
    kindLabel,
    setKind(slot, kind) {
      if (kind === 'raw' && !slot.raw) {
        try { slot.raw = JSON.stringify(schemaFromKind(slot.kind, '') || { type: 'object' }, null, 2); } catch (_) { slot.raw = '{}'; }
      }
      slot.kind = kind;
    },

    // -- Drag and drop --
    // Alpine renders the lists from arrays, so after SortableJS moves a DOM node
    // we put the node back and move the array item instead; Alpine then
    // re-renders in the new order.
    sortable(el, getList, opts = {}) {
      const revert = (evt) => {
        const siblings = [...evt.from.children].filter((c) => c !== evt.item && c.tagName !== 'TEMPLATE' && (!opts.draggable || c.matches(opts.draggable)));
        evt.from.insertBefore(evt.item, siblings[evt.oldDraggableIndex] || null);
      };
      return Sortable.create(el, {
        handle: opts.handle || '.drag',
        ...(opts.draggable ? { draggable: opts.draggable } : {}),
        animation: 150,
        ghostClass: 'is-ghost',
        group: opts.group,
        filter: '.no-drag',
        preventOnFilter: false,
        onUpdate: (evt) => {
          revert(evt);
          const list = getList();
          const [moved] = list.splice(evt.oldDraggableIndex, 1);
          list.splice(evt.newDraggableIndex, 0, moved);
        },
        onAdd: (evt) => {
          const method = evt.item.dataset.method;
          // Put the palette chip back (it keeps its listeners) and drop Sortable's copy.
          if (evt.pullMode === 'clone' && evt.clone) evt.clone.replaceWith(evt.item);
          else evt.item.remove();
          if (method) this.addEndpoint(method, evt.newDraggableIndex);
        },
      });
    },
    palette(el) {
      Sortable.create(el, { group: { name: 'endpoints', pull: 'clone', put: false }, sort: false, animation: 150 });
    },
  });
})();
