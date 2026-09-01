# Spora Plugin: Typst

Compile [Typst](https://typst.app/) source to PDF / PNG / SVG from inside a
Spora agent conversation. Backed by [ext-typst](https://ext-typst.carthage.software/) and the
`media-derivatives` abstraction in `spora-core`.

## What's in the box

- 3 LLM-callable tools: `typst_render`, `typst_inspect`, `typst_resources`.
- 6 REST routes under `/api/v1/typst/{fonts,examples}*`.
- 1 admin app (`/apps/typst`) — manage fonts and example templates.
- 1 agent template (`typst-assistant`).
- The `typst` skill body — covers the workflow, syntax primer, and limit checklist.
- Inter OFL (Regular + Bold) shipped under `skills/typst/fonts/` — always
  available without an upload.
- 1 starter Typst example (`skills/typst/examples/invoice.typ`).
- A `TypstRenderProducer` that registers with `MediaDerivativeProducerDiscovery`
  so any admin surface can dispatch into it.

## Architectural rule — **works without spora-plugin-media-archive**

The Typst plugin **does not depend on `spora-plugin-media-archive`**. Two
things make this work:

1. **Direct PHP integration with the derivatives core.** The `typst_render`
   tool calls `MediaDerivativeService::create()` directly via the DI
   container — no HTTP hop into a plugin-only controller. The derivatives
   surface in chat via `MediaEmbed` markdown referencing the core's
   `/api/v1/assets/<uuid>.<ext>` route (served by core's `AssetController`,
   not by any plugin).

2. **Independent admin UI.** The `/apps/typst` admin panel handles Typst
   resources (fonts, examples) without crossing the Media Archive plugin's
   namespace. Operators can install `spora-plugin-typst` standalone.

The Media Archive plugin is a **value-add consumer** of the derivatives
abstraction — its `VersionsStrip` UI (when installed) renders the Typst
derivatives alongside other media derivatives, but the Typst plugin doesn't
require it.

## Requires

| | |
| --- | --- |
| PHP | `^8.4.1` |
| `ext-typst` | `*` ([Carthage Software](https://ext-typst.carthage.software/) — install via PECL or your distro's package manager) |
| spora-core | `dev-feat/media-principal-coverage` (see *Bootstrap* below) |

## Bootstrap

```bash
composer install
vendor/bin/pest            # runs the plugin's Pest suite
vendor/bin/phpstan analyse # PHPStan level 5
```

The plugin's `composer.json` declares a `repositories` entry pointing at
`https://github.com/spora-ai/spora-core.git` and requires the
`feat/media-principal-coverage` branch by alias. That branch is the open
PR that adds the `MediaDerivativeProducerInterface` + `MediaDerivativeService`
this plugin builds on. Once that PR merges, the `[spora-ai/spora-core]`
version in `composer.json` should be bumped to the next tagged release
(`^0.19.0` or whichever ships the media-derivatives surface).

## Tests

```
Tests:    28 passed (78 assertions)
Duration: ~1.8s
```

The plugin's test suite has two parts:

- **Unit tests** under `tests/Unit/` — services, producer, world factory.
  The producer tests skip themselves when `ext-typst` is not loaded so CI
  on vanilla ubuntu runners can still run the rest of the suite.
- **Feature test** under `tests/Feature/` — end-to-end through
  `MediaDerivativeService::create()`, asserting natural-key idempotency
  on the second call.

## Tool surface

| Tool | Operations | Notes |
| --- | --- | --- |
| `typst_render` | `execute` | Compiles source → PDF / PNG / SVG. Persists as a media-derivative. The default output is `pdf`; pair with `format: png` for chat-embeddable images. |
| `typst_inspect` | `execute` | Inspector-only pass. Returns structured errors + warnings without producing bytes. Use this to fast-iterate on a draft. |
| `typst_resources` | `resources_list` / `resources_write` / `resources_delete` | Multi-op. Read-only or destructive ops require operator approval. |

`typst_render` accepts the source as either:

- `source` (inline UTF-8 Typst source) — persisted as a transient
  `text/x-typst` parent row.
- `file` (a previously-uploaded `.typ` media asset id) — renders the
  parent bytes. Re-rendering the same `(file, format)` pair refreshes the
  existing derivative row.

## REST surface

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/typst/fonts` | List visible fonts (skill + principal) |
| `GET` | `/api/v1/typst/fonts/{name}` | Stream a font's bytes |
| `POST` | `/api/v1/typst/fonts` | Upload a principal-tier font (raw text or base64) |
| `DELETE` | `/api/v1/typst/fonts/{name}` | Remove a principal-tier font |
| `GET` | `/api/v1/typst/examples` | List visible examples |
| `GET` | `/api/v1/typst/examples/{name}` | Stream an example's source |
| `POST` | `/api/v1/typst/examples` | Upload a principal-tier example |
| `DELETE` | `/api/v1/typst/examples/{name}` | Remove a principal-tier example |

All routes sit behind `AuthMiddleware` + `CsrfMiddleware`.

## Two-tier resource layout

- **Tier 1 (skill-shipped, read-only).** Resolved via
  `Composer\InstalledVersions::getInstallPath()` to the plugin's
  `skills/typst/` directory. Inter-Regular.otf, Inter-Bold.otf, and
  the starter invoice example ship here. Operators cannot delete tier-1
  resources.

- **Tier 2 (principal, writable).** Stored under
  `<storage>/typst/{fonts,examples}/<principal-id>/`. Tier-2 wins on
  basename collision.

Listing returns the union (deduplicated by basename, tier-2 first).
Reads consult tier-2 first, then tier-1.

## Image library

The plugin also ships a **per-principal image library** — agents can
upload PNG / JPEG / WebP / SVG and reference them in Typst source via
`#image("…/api/v1/assets/<uuid>.png")`. Each image is a real
`media_assets` row tagged with `plugin_slug='spora-plugin-typst'` and
`tool_name='typst.image'`, so it shows up in the media library's LIST
endpoint and the Media Archive plugin's Versions UI when that plugin is
also installed.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/typst/images` | List images visible to the caller (principal-scoped) |
| `POST` | `/api/v1/typst/images` | Upload an image (`{ filename, mime, content }` — content is base64 or raw UTF-8 for SVG) |
| `DELETE` | `/api/v1/typst/images/{id}` | Delete an image by id (404 if not found or owned by another principal) |

Images are capped at `TypstImageStore::MAX_BYTES` (5 MiB) per upload and
limited to the four MIMEs ext-typst can `#image()` natively. The
controller uses the host's `AssetStore` to persist bytes, so storage
mode (`data_url` vs `local`) follows the operator's existing
configuration.

### Architectural distinction

Fonts and examples are plugin-private files (raw bytes, no `media_assets`
row, no canonical asset URL). Images are full `media_assets` rows with a
canonical `/api/v1/assets/<uuid>.<ext>` URL the chat UI can resolve.
That difference is why fonts/examples use a basename-keyed storage path
while images use the `media_assets.id` UUID as the addressable key.

## Local development

In `spora-local`, add the plugin as a path repo to test it against the
running skeleton:

```jsonc
// spora-local/composer.json
{
    "repositories": [
        { "type": "path", "url": "../spora-plugin-typst", "options": { "symlink": true } }
    ],
    "require": {
        "spora-ai/spora-plugin-typst": "@dev"
    }
}
```

Then `composer update spora-ai/spora-plugin-typst`. The plugin's
`register()` hook adds the `TypstRenderProducer` to
`MediaDerivativeProducerDiscovery`; the routes are picked up by the
plugin loader automatically.

## Layout

```
.
├── composer.json          # spora-ai/spora-plugin-typst + ext-typst + spora-core@feat/media-principal-coverage
├── plugin.json            # manifest (class=FQCN, slug=typst, icon=file-type-2)
├── src/
│   ├── TypstPlugin.php                # entry point (register, routes, tools, apps)
│   ├── TypstApp.php                   # admin-app metadata (name=typst, entry=main.js)
│   ├── Exceptions/
│   │   └── TypstCompilationException.php
│   ├── Http/
│   │   ├── TypstFontController.php    # GET/POST/DELETE /api/v1/typst/fonts
│   │   └── TypstExampleController.php # GET/POST/DELETE /api/v1/typst/examples
│   ├── Producers/
│   │   └── TypstRenderProducer.php    # MediaDerivativeProducerInterface impl
│   ├── Services/
│   │   ├── TypstResourcePaths.php     # tier-1 + tier-2 path resolution
│   │   ├── TypstResourceStore.php     # list/read/write/delete for tier-2
│   │   └── TypstWorldFactory.php      # builds Typst\World + Compiler + Inspector
│   └── Tools/
│       ├── AbstractTypstTool.php      # shared source-resolution + visibility
│       ├── TypstRenderTool.php
│       ├── TypstInspectTool.php
│       └── TypstResourcesTool.php
├── skills/typst/
│   ├── SKILL.md                       # ~250 lines: workflow, syntax primer, limits
│   └── examples/invoice.typ           # starter template
├── agent-templates/
│   └── assistant.json                 # typst-assistant agent template
├── tests/
│   ├── Pest.php                       # shared bootstrap + DB migration
│   ├── bootstrap.php                  # BASE_PATH + autoload
│   ├── Pest.config.php
│   ├── Unit/
│   │   ├── Producers/TypstRenderProducerTest.php   # 9 cases, skips when ext-typst absent
│   │   └── Services/
│   │       ├── TypstResourcePathsTest.php          # 7 cases
│   │       ├── TypstResourceStoreTest.php          # 8 cases
│   │       └── TypstWorldFactoryTest.php          # 3 cases
│   └── Feature/
│       └── TypstDerivativeIntegrationTest.php     # 2 cases (register, end-to-end create)
└── .github/workflows/ci.yml           # pest + phpstan + cs-fixer
```

## CI

Three parallel jobs (the standard Spora-plugin CI):

- `test` — Pest (PHP 8.4 + 8.5 on `ubuntu-latest`).
- `static-analysis` — PHPStan level 5 (memory limit 512M).
- `code-style` — `php-cs-fixer` dry-run.

Tests that depend on `ext-typst` skip themselves when the extension is
unavailable, so the suite is green on vanilla ubuntu runners. The CI
matrix can be extended to include a `macos-14` leg that builds
`ext-typst` from source — left as a follow-up to keep this PR focused.

## Publishing

1. `git tag v0.1.0 && git push --tags`.
2. Configure Packagist to auto-pull from the GitHub repo.

The runtime reads the version from the git tag via
`Composer\InstalledVersions::getPrettyVersion()`.

## Authoring guidelines

Framework-level conventions — which classes are plugin-stable, what's
framework-internal, schema versioning, deprecation policy — live in the
[Spora docs → Plugin system](https://docs.spora-ai.com/reference/concepts/plugins-system).
Route plugin logic through `AgentOrchestrator` and `TaskService`; do not
import framework-internal driver classes.
