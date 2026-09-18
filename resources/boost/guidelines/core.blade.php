@verbatim
## Media Module

The Media module is a Noerd tenant app (Composer package `noerd/media`, namespace `Noerd\Media`,
tenant app `MEDIA`) — a multi-tenant media library with folders, tags and generated thumbnails
that other modules reach ONLY through the core `MediaResolverContract` and the
`MediaUsageRegistry`. The framework rules (lists, details, pages, modals, themes, tests,
translations) come from the `noerd/noerd` guideline — this block only adds what is specific to
this module.

### Domain
- `Media` (table `medias`, `$guarded = []`, `BelongsToTenant`) — columns `tenant_id`, `disk`,
  `path`, `thumbnail`, `name`, `extension`, `size`, `type`, `folder_id`; `custom_attributes` is
  cast to `array` for project-specific fields. Relations: `folder()` (belongsTo `MediaFolder`),
  `tags()` (belongsToMany `MediaTag` through `media_tag_media`)
- URL access goes through the model, never through `Storage::url()` in a view: `url()` and
  `thumbnailUrl()` honour `config('media.private')` (direct `/storage/media/…` URL in public mode,
  the authenticated `media.file` / `media.thumbnail` routes in private mode);
  `hasRenderableThumbnail()` says whether an `<img>` can show it — otherwise render a file-type
  tile, never a broken image. The tile is the partial `media-thumbnail`, whose icon and colour come
  from `Noerd\Media\Support\FileTypeIcon::for($extension)` (a `heroicons::outline.*` name plus the
  literal Tailwind classes — a PDF is red, a spreadsheet green, the unknown extension grey). Extend
  that map instead of branching on an extension in a view
- `MediaFolder` (table `media_folders`, `BelongsToTenant`) — self-referencing `parent()` /
  `children()` (ordered by name), `medias()`, `breadcrumb()` walks the parent chain; `app_name` +
  `system_key` mark an APP FOLDER (see below): `isSystem()`, `label()` (translated name — render
  folder names through it, never `$folder->name`). `path_segment` is the folder's DIRECTORY name,
  derived from `name` by the model's `saving` hook and unique per tenant and parent — never write
  it by hand and never build a directory name from `name` or `label()` (a label is translated)
- `MediaTag` (table `media_tags`, NOT tenant scoped) — pivot `media_tag_media`; the tables were
  renamed from `media_labels` by the `rename_media_labels_to_tags` migration
### The disk mirrors the library — read this before touching a path

- Files live on the dedicated `media` disk (`config('media.disk')`, env `MEDIA_DISK`) at
  `{tenant_id}/{folder segments}/{name}`: the storage root carries the SAME tree as the library, so
  it can be browsed, backed up and filled by hand. `medias.name` IS the basename on disk; a name
  the target directory already holds is numbered (`beleg-2.pdf`). Thumbnails stay flat in the
  hidden `{tenant_id}/.thumbnails/` as `thumb_*.jpg` (images, 500px wide via Intervention Image GD)
  or `pdf_*.jpg` (PDF page 1), the delivery variants in `{tenant_id}/.variants/{variant}/` as
  `{mediaId}_{width}.webp` — both are generated data, and the reconciler skips dot directories
- Names stay READABLE: only what breaks a path is stripped (separators, control characters,
  wildcards, a leading dot) — umlauts and other UTF-8 characters survive, in folder names and file
  names alike. A transliterated `Vertraege` next to a hand-made `Verträge` directory would be two
  folders for one thing. A caller that KNOWS the directory name (the reconciler, reading it off the
  disk) sets `path_segment` itself and the model leaves it alone
- `MediaPathService` is the ONLY place a path is built (`segmentFor()`, `uniqueSegment()`,
  `folderPath()`, `pathFor()`, `uniqueFilename()`, `thumbnailDirectory()`); `MediaMover` is the
  only place bytes move (`moveToFolder()`, `relocate()`, `relocateFolder()`, `deleteFile()`,
  `ensureDirectory()`, `removeDirectory()`, `pruneEmptyDirectories()`). Never concatenate a path
  and never call `Storage::move()`/`delete()` on a media file directly
- The mover is a SERVICE, not a model observer, on purpose: folder changes used to be mass
  `update()` queries, which fire no model events. Every call site asks the mover explicitly — the
  library's move, its folder-delete cascade, the upload, and consuming modules (accounting's
  `ReceiptFolderService::moveToDocuments()`). A module that "moves" a file by writing `folder_id`
  leaves the bytes behind
- A consequence for consumers: a file's path is only true until it is moved. NEVER persist a media
  URL or path in another table — persist the media id and resolve the URL when rendering
  (`MediaResolverContract::getImageUrl()` for visitors, `getPreviewUrl()` for a backend tile,
  `getRelativeUrl()` for the original, or `storeUploadedFileReference()` for an upload)
- Both commands run headless (explicit `tenant_id`, `withoutGlobalScopes()`) and only touch
  directories named after an existing tenant: the media disk may hold content the library does not
  own (CRM print mailings when `crm.storage.disk` points at it)

### Resolver and usage registry
- The provider binds `Noerd\Contracts\MediaResolverContract` to `Noerd\Media\Services\MediaResolver`
  (the core falls back to `NullMediaResolver` via `singletonIf` when Media is not installed).
  Consuming modules and core field types (`image` field, `setup-collection-detail`) depend ONLY on
  the contract: `getPreviewUrl()`, `exists()`, `getRelativeUrl()`, `getImageUrl()`,
  `storeUploadedFile()`, `isAvailable()`, `pickerComponent()` — never `use` a `Noerd\Media\*` class from an optional
  module that does not declare `noerd/media` in its `composer.json`
- `pickerComponent()` returns `media::media-list`: open it with `Noerd::modal('media::media-list',
  [...])` passing `selectMode`, `selectContext`, `selectToken` (or `listActionMethod: 'selectAction'`
  from a relation field); the list answers `mediaSelected($mediaId, $context, $token)` and closes
  itself — the opener listens with `#[On('mediaSelected')]`
- `MediaUsageRegistry` (singleton) is the ONLY way a module keeps a file from being deleted:
  register a check in the consuming provider's `boot()` —
  `app(MediaUsageRegistry::class)->register('{module}.{usage}', fn (Media $media): ?string => …)`
  returning a human-readable reason or `null`. `Media::booted()` asks the registry on `deleting`
  and throws `MediaInUseException` (public `$reason`), so EVERY deletion path (list, bulk, tinker)
  is covered; the library shows the reason in `deleteError`. A check that throws is reported and
  ignored — it must never make a file undeletable. The media module itself never knows which
  modules exist (`ModuleBoundaryTest`)

### App folders (AppFolderRegistry)
- A module that needs a folder in every tenant's library (a receipt inbox its agent reads, an
  archive it files documents into) registers it in its provider's `boot()`:
  `app(AppFolderRegistry::class)->register('{module}.{folder}', '{APP}', 'Label')` — the key is
  stable, the app is the tenant-app name, the label an English translation key of THAT module
  (`de.json`). Never create such a folder with `MediaFolder::create()` from another module
- `AppFolderService` (request-scoped) creates the rows: `ensureForTenant()` (called by
  `media-list::mount()`), `ensureForApp()` (the `TenantAppAssigned` listener
  `EnsureAppFoldersOnAppAssignment`), `ensureForAllTenants()` (install/update commands) and
  `resolve($tenantId, $key)` — the one a module calls when it needs the folder (created on
  demand, so no event has to have fired). All of it runs `withoutGlobalScopes()` with an explicit
  tenant id — usable from the scheduler
- Protection lives on the model (`MediaFolder::booted()`): deleting, renaming or moving an app
  folder throws `SystemFolderProtectedException`; the library hides the delete button and
  `deleteFolder()` ignores it. Users may create sub-folders inside; a module renaming its label
  is applied quietly by the service
- Visibility: `AppFolderVisibilityScope` is a GLOBAL scope on `MediaFolder` AND `Media` — app
  folders whose app the user may not use (`AccessHelper::canUseApp()`: not assigned to the
  tenant, or denied by the app permission / noerd-plus grants) plus their sub-folders and files
  disappear from every query: library, global search, folder picker, route-model binding of the
  file routes, client-supplied folder ids (`openFolder`, `moveMediaToFolder`, `folder-create`).
  Same contract as `TenantScope`: no signed-in user or no selected tenant → unfiltered. The hidden
  ids are memoized per request in `AppFolderAccess` (`forget()` in tests after changing access)

### Uploads and thumbnails
- The upload UI is the core dropzone (`<livewire:dropzone wire:model.live="files" :rules="…">`,
  shipped by `noerd/noerd`) inside `media-list`; the rules come from `uploadRules()` and are
  CONFIGURATION: `config('media.allowed_extensions')` and `config('media.max_upload_size')` (KB) in
  the project's published `config/media.php` — never widen the list in module code
- Programmatic storing goes through `MediaUploadService` (`storeFromArray()` for dropzone-style
  arrays, `storeFromUploadedFile()` for `UploadedFile`s) which writes the file, generates the
  preview through `ImagePreviewService` and creates the `Media` row; both read the tenant from
  `Auth::user()->selected_tenant_id`. Filenames are ASCII-sanitised (`Str::ascii(…, 'de')`)
- PDF thumbnails are rasterised by `PdfThumbnailGenerator`: Ghostscript (`gs`, auto-detected on
  `$PATH` plus Homebrew paths, or `config('media.ghostscript_binary')` / `MEDIA_GHOSTSCRIPT_BINARY`)
  first, the legacy `imagick` extension only as fallback. Neither is required — without a renderer
  the PDF is stored WITHOUT a thumbnail and the library shows a file-type tile. Never make a
  renderer a hard dependency and never let a failed rasterisation abort the upload
- Private media: `config('media.private')` (`MEDIA_PRIVATE`) relocates the `media` disk to
  `storage/app/media` at boot (`MediaServiceProvider::configurePrivateDisk()`); files are then only
  reachable through `MediaFileController` (`media.file`, `media.thumbnail`), which streams to a
  logged-in user of the SAME tenant (404 otherwise). Move existing files with `noerd:media-relocate`.
  Images a public page embeds through `getImageUrl()` keep working in private mode — the signed
  `media.image` route streams them; an SVG or PDF is then not reachable for anonymous visitors

### Image variants (delivery to visitors)

- The original is stored UNTOUCHED (a receipt photo, an archive scan keeps every pixel) and the
  500px thumbnail is a backend tile. What a VISITOR gets — public website, e-mail — is a
  size-limited variant: `Media::imageUrl($variant = 'web')` /
  `MediaResolverContract::getImageUrl($mediaId, $variant)`. Never put `url()` /
  `getRelativeUrl()` of an image into public markup — that delivers the oversized original
- Variants are CONFIGURATION: `config('media.variants')` (name => maximum width, shipped
  `web => 1920`), `variant_quality`, `variant_max_pixels`. A project adds names (`teaser => 640`)
  in its published config — never a width in module code
- `ImageVariantService` is the only place a variant is made: `supports()` (png/jpg/jpeg/webp AND
  a configured name), `pathFor()` (generated on the FIRST request — `scaleDown()`, never upscaled,
  WebP, or PNG/JPEG when GD lacks WebP — then cached; `null` = deliver the original: above the
  pixel budget GD would run out of memory, which cannot be caught), `forget()` (called by
  `MediaMover::deleteFile()`). The cache key is the media id, so moving a file keeps its variants;
  the width is part of the file name, so a changed width never serves a stale size —
  `media:clear-variants` drops the old files. It reads bytes through `Storage::get()`, never
  `->path()`: the variant path works on any disk
- The route `media.image` (`/media/image/{mediaId}/{variant}`) sits OUTSIDE the `web` and `noerd`
  groups — no session, no login — behind `signed:relative`. The signature is the authorization:
  only a URL the application rendered is answered, so ids cannot be enumerated (receipts share the
  table), and it is relative because websites run on their own domains. No expiry: the URL is
  stable and the response is `immutable`, `v` (the row's `updated_at`) busts the cache. The
  controller looks the row up `withoutGlobalScopes()` — the visitor is anonymous, and a backend
  user of another tenant must not lose the image to the tenant scope. A file that cannot be scaled
  (SVG, GIF, AVIF, PDF) gets its plain original URL from `imageUrl()` instead of the route

### Structure
- Livewire components (flat, `resources/views/components/`, namespace `media::`): `media-list`
  (the library: `NoerdList` + folder tree, tag filter, multi-select, dropzone upload, picker mode;
  NOT a slim list — it overrides `with()`; opens `media::folder-create` and `media::folder-picker`
  as component modals and listens for `mediaFolderCreated` / `mediaFolderPicked`), `folder-create`,
  `folder-picker`, the Blade partials `media-thumbnail` and `partials/folder-tree-node`, the app
  icon `icons/app` (`media::icons.app` — the one hand-made icon, returned by `getAppIcon()`).
  The library's filter row leads with the file count of the open folder (`totalCount` from `with()`,
  the same query the grid runs) — the grid loads more tiles as the user scrolls, so the number of
  tiles never answers "how much is in here"
- There is no `media-detail` component: the selected file is edited inline in the library
  (`$selected`, tags, folder move)
- YAML: `app-configs/media/lists/media-list.yml` + `navigation.yml` — keep the module copy and
  the installed project copy (`app-configs/media/…`) in sync
- Routes: `routes/media-routes.php` — `media.dashboard` (the library, middleware
  `['noerd', 'app-access:media']`) plus `media.file` / `media.thumbnail` under `['noerd']` ONLY —
  previews are embedded in other apps, so any logged-in tenant user must load them; never add
  `app-access:media` to the file routes
- Config: `config/media.php` (`disk`, `private`, `allowed_extensions`, `max_upload_size`,
  `ghostscript_binary`, `variants`, `variant_quality`, `variant_max_pixels`), merged via `mergeConfigFrom()` and published by the install command
  (existing file left untouched) — new keys go into the module config AND the host copy
- Translations: `resources/lang/de.json` (English keys); migrations / factories (`MediaFactory`
  with the `file($tenantId, $name)` state, `MediaFolderFactory`) / tests live inside the module

### Commands
- `php artisan noerd:install-media` — adds the `media` disk to `config/filesystems.php`, publishes
  `config/media.php`, installs the YAML configs, registers the `MEDIA` tenant app, runs migrations
- `php artisan noerd:update-media` — idempotent update of the YAML configs (picked up by
  `noerd:update-all`); both commands end with `AppFolderService::ensureForAllTenants()`
- `php artisan media:regenerate-thumbnails {--missing} {--all} {--id=}` — regenerates thumbnails
  through `ImagePreviewService::regenerateThumbnail()` (console-safe: uses `$media->tenant_id`)
- `php artisan noerd:media-relocate {--to=private|public}` — moves the files between the public
  and the private storage root when toggling `media.private`
- `php artisan media:restructure {--tenant=} {--dry-run}` — the ONE-TIME move of an installation
  from the historic flat layout into the folder-mirroring one, thumbnails included. Run it after
  `migrate`, and after the CMS/core migrations that turn stored media URLs into ids — those map the
  URLs back through the still-flat `medias.path`
- `php artisan media:sync {--tenant=} {--prune} {--dry-run}` — reconciles library and disk in both
  directions: unknown directories become folders, unknown files become media (thumbnail included),
  and media rows without a file are reported. `--prune` deletes those rows THROUGH the model, so
  the `MediaUsageRegistry` still refuses a file another module needs. A file moved by hand shows up
  as an import plus an orphaned row — there is no content hash. The module schedules nothing
- `php artisan media:clear-variants {--tenant=}` — deletes the cached delivery variants (always
  safe, they are regenerated on the next request); run it after changing a width in
  `media.variants`

### Tests
- Pest tests in `tests/`, bound to the host `Tests\TestCase` (+ `RefreshDatabase` where data is
  needed); run with `php artisan test --compact app-modules/media/tests`
- Always `Storage::fake('media')` — never write into the real disk. Users come from
  `NoerdUser::factory()->withExampleTenant()->withSelectedApp('media')`
- `MediaFactory::file($tenantId, $name, $folderId)` derives the path from the folder, so a fixture
  really sits where the library says it does — never hand-write a `path` in a fixture
- PDF rendering is isolated: `tests/Support/FakeGhostscript` writes throwaway `gs` stubs
  (`writingJpeg()`, a failing variant) and points `media.ghostscript_binary` at them;
  `tests/Support/PdfRendering::isWorking()` decides whether a real-renderer integration test skips
- Usage guards are proven with an ad-hoc `MediaUsageRegistry::register('zz', …)` check
  (`MediaUsageGuardTest`) — the module tests without any consuming module; app folders the same
  way with the made-up app of `tests/Support/CreatesAppFolderFixtures` (`zzRegisterFolder()`,
  `zzAssignFolderApp()`, `zzDenyFolderApp()` simulating a noerd-plus denial through the gate)
- Prove mechanics, never the current YAML configuration (see the `noerd-testing` skill)

### Reference implementations
- `src/Services/MediaResolver.php` + `tests/Services/MediaResolverTest.php` — implementing a
  core contract behind which a module stays optional
- `src/Services/MediaUsageRegistry.php` + `tests/Feature/MediaUsageGuardTest.php` — the
  "ask outward" registry pattern (a consuming registration lives in that module's provider, e.g.
  `accounting.receipts`)
- `src/Services/AppFolderRegistry.php` + `AppFolderService.php` + `src/Scopes/AppFolderVisibilityScope.php`
  with `tests/Feature/AppFoldersTest.php` / `AppFolderVisibilityTest.php` — app-owned, protected,
  permission-scoped folders (a consuming registration lives in the accounting provider)
- `src/Services/PdfThumbnailGenerator.php` + `tests/Support/FakeGhostscript.php` — an optional
  external binary with graceful degradation and a test double
- `src/Commands/NoerdMediaInstallCommand.php` — an install command that also patches host config
  (`filesystems.php`) and publishes its own config idempotently
- `src/Http/Controllers/MediaFileController.php` + `tests/Feature/MediaFileRouteTest.php` —
  tenant-checked file streaming
@endverbatim
