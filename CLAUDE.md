# Glance — notes for Claude

Self-hosted, multi-user web media browser/viewer over a big filesystem tree (`MEDIA_PATH`). Laravel 13 / PHP 8.4,
Blade + Tailwind 4 + Alpine/jQuery/Fancybox/hls.js via Vite. Long-lived personal project: the project plan and design
notes (DB caching plan, metadata/search ideas) live in `README.md`; read it before any feature work.

## Workflow

- Commit straight to `main`; no branches/PRs. **Never commit without the user's review.** Prepare a commit message and
  ask; several related tasks are often batched into one commit.
- Commit message style (see `git log`): short imperative subject ("Fix …", "Implement …", "Update …"), blank line, then
  a prose paragraph or `*` bullets explaining *why* (root cause, upstream breakage, browser quirk). No conventional-commit
  prefixes.
- **Never put live directory or file names from the media library into committed changes** — code, tests, comments,
  docs or commit messages. Use generic placeholders (`Some/Dir/file.jpg`) and the fixtures in `resources/test-media/`.
  Committed history on `main` is clean. The user's local, uncommitted experiments do contain live names (listing
  hacks in `MediaController::index()`, an extra ignore pattern in `IndexDirectoryJob`, the `/_test_frame` route) —
  never stage those hunks; when committing a file that has them, stage only the intended hunks and check the diff.
- The working tree often has the user's own uncommitted WIP (e.g. the `/_test_frame` debug route in `routes/web.php`,
  `resources/lightbox/` legacy predecessor app, `stashdb.graphql`). Don't revert, reformat or stage it unless asked.

## Commands

```bash
php artisan test                      # PHPUnit (89 tests, ~2s); sqlite :memory:, sync queue, MEDIA_PATH=resources/test-media
php artisan test --filter=PathTest
vendor/bin/pint path/to/File.php      # format only files you touched (see Style)
composer dev                          # serve + queue:listen + pail + vite
npm run build                         # outputs to public/_build (Vite::useBuildDirectory('_build'))
php artisan app:index [-a] [-d N] [-r] [-f] [-g] [path...]   # (re)index; -a async via queue, -r force recurse,
                                      # -f force file update, -g generate missing thumbs/hashes/text
php artisan app:clean-images [--dry-run] [--delete-filmstrip]   # repair/remove orphaned or missing generated images
php artisan app:hash [-T] [-m method...] [-r reference] [path...] # compute oshash/phash for files (debugging)
# app:transcode is internal — spawned by TranscodeManager, not run by hand
```

## Style

- Pint `laravel` preset with `not_operator_with_successor_space: false` → write `!$x`, not `! $x`.
- **`pint --test` currently fails on ~16 pre-existing files** (`fully_qualified_strict_types`, `ordered_imports`, from
  a newer Pint). Don't run Pint repo-wide or let it rewrite unrelated code in a file; ask before a mass fix.
- Code inlines FQCNs like `\App\Jobs\ExtractTextJob::…` and `\SplFileInfo` in places — fine to match.
- Lots of explanatory `// TODO:` comments documenting known trade-offs and future plans; keep and extend that habit
  rather than deleting them.
- `Model::shouldBeStrict()` is on: no lazy loading (eager-load relations), no silently-discarded attributes, no missing
  attribute access. Use `#[WithoutRelations]` on models passed into jobs.

## Architecture

### Request path (`MediaController`)
- All app URLs are a catch-all `/{path}` (`dashboard` route). Internal routes are `_`-prefixed (`/_search`, `/_info`,
  `/_stream`, `/_image/{uuid}.avif`, `/_admin`, `/_profile`, `/_health`) so they can't clash with media paths.
- Always normalise user paths with `Utilities\Path::resolve()` (strips `.`/`..`/empty segments). Build links with
  `media_url($path)` (`app/helpers.php`) — it encodes segments once; Laravel 13 double-encodes pre-encoded route params.
- `ensurePathCanonical()` enforces trailing slash for directories, none for files.
- Files are served with `response()->file()`; in prod `MEDIA_TRUST_X_SEND_FILE` hands off to Nginx (static files must
  also be authorized). Clockwork is disabled for file/image responses.
- Directories: rendered from the **DB index**, not the filesystem. If the `IndexedDirectory` is missing/outdated
  (mtime/inode), an `IndexDirectoryJob` batch is dispatched and a self-refreshing progress page is shown
  (`ensureDirectoryIndexed`, batch id cached per path).
- Listing: `Media\Sources\DirectorySource` → `MediaContentItem`s, grouped by `MediaContentKind` (enum case order =
  display order), natural-sorted, paginated in PHP (100/page) via `GroupedMediaPaginator`. Per-directory
  "source"/"renderer" plugins are planned (`getSourceForPath()` is a stub returning `DirectorySource`).
- `HttpException`s are rendered by `MediaController::handleHttpException` (`bootstrap/app.php`).
- `/_stream/{file}.m3u8[/{n}.ts]` → `Media\TranscodeManager`/`MediaTranscode`: live FFmpeg HLS transcode per
  session+file, coordinated with cache locks (no B-frames — Chrome native HLS needs DTS order).

### Access control (security-critical)
- `AccessControlEntry` (path + users pivot). `User::getPathAccessInfo($path)` returns
  `['' => bool accessToPath, '<childName>' => bool override, …]`; last matching ancestor rule wins, descendant rules
  become per-child overrides. Root rule path is `''`.
- Lists are filtered with the `ScopeHavingAccess` trait (`havingAccess($info)` on `name`). Child names may be numeric →
  PHP int array keys; always `strval` when comparing (past bug).
- Admins can impersonate for ACL purposes: always use `User::getAccessControlOverrideUser($request) ?? $user`.
- Not-allowed ⇒ `abort(404)` (never 403, don't leak existence). Gate `admin` = `users.is_admin`; also guards Horizon.
- `EnsureUserIsActivated` (`activated` middleware): users are invite-only (`InvitedUserController`), no registration.
- Any new endpoint touching paths must do the ACL check; tests in `tests/Feature/AccessControlTest.php`.

### Indexing pipeline (`app/Jobs`, Horizon on Redis/Valkey)
- `IndexDirectoryJob` diffs disk vs `indexed_directories`/`indexed_files`, creates/updates rows, **force-deletes**
  vanished entries (soft deletes exist but aren't used yet), recurses by adding jobs to the same batch. Ignore regex
  skips dotfiles and `preview_*`.
- `IndexFileJob` stats the file and chains generation jobs for what's missing: `ProbeMediaFileInfoJob` (ffprobe →
  `MediaInfo` cast), `GenerateMediaFileThumbnailJob`, `GenerateMediaFileFilmstripJob`,
  `GenerateMediaFilePerceptualHashJob` (Stash-compatible phash via `VideoPerceptualHasher`), `ExtractTextJob`.
  Under a `sync` batch (CLI) generation runs inline.
- **What's known purely from a file's contents lives on its `FileFingerprint`** (`file_fingerprints`: unique `oshash`,
  `phash`, `stash_scene_id`), not on the `IndexedFile`. Fingerprints are found by hash and are never deleted with their
  files, so they survive the indexer deleting and recreating a moved file, and copies share one. `IndexFileJob` only has
  to rewrite the scene's searchable text (which belongs to the file). `requiresGeneration()` needs `fingerprint` loaded.
  Query conditions on the hash columns aren't cast: pass `hex2bin()` values (see `findOrCreateForHash()`).
- `appendToChain()` returns the *current* job, so queue closures with `CallQueuedClosure::create(...)->onQueue()`;
  chaining `->onQueue()` after it silently sends them to `default`.
- Queues (see `config/horizon.php`): `default` on `redis_blocking`; `index`, `ffprobe`, `generation-hash`,
  `generation-tile`, `ffmpeg-capture`, `ffmpeg-wait` on `redis`, priority order, `tries=1`, `timeout=60`.
  `WaitForFrameCaptureSlot` works around Horizon draining low-priority queues ahead of newly-fed high ones.
  Scout syncing (`MakeSearchable`/`RemoveFromSearch`) goes to `search`, a single-process supervisor: the SQLite indexes
  allow one writer, and `FilterableSqliteEngine` takes the write lock up front (`BEGIN IMMEDIATE`) so writers wait
  rather than failing with "database is locked". It also resets upstream's cached statements after each write: a
  part-read SELECT kept a read snapshot open, so the WAL could never restart and grew to tens of GB.
- Generated images (thumbs/filmstrips) are AVIF via libvips (`jcupitt/vips`), stored at
  `MEDIA_PATH/.thumbs/xxxx/yyyy/<uuid>.avif` (`Path::makeThumbnailPath`), served by `/_image/{uuid}.avif`.
  `IndexedFile` model events delete old images on change/force-delete; `app:clean-images` repairs drift.
- `ExtractTextJob` (~900 lines): Tesseract (`/usr/bin/tesseract`, TSV, PSM 11) on vertically tiled images with box
  merging/ordering and enchant dictionary checks; also text/HTML extraction. Text stored compressed in
  `indexed_file_texts` chunks; status in `ExtractedTextStatus`. Admin debug: `?_debug_ocr` on an image URL.
- `SearchScenesInStashBoxJob`: phash lookup against StashDB GraphQL (`config/stashbox.php`, `STASHDB_API_KEY`;
  schema in `stashdb.graphql`), populates `stash_*` tables. Not wired to any command/schedule — dispatched manually.
  It searches for `FileFingerprint`s (not files) that have a phash, a video file and no `stash_searched_at`, and stores
  the scene on the fingerprint; `stash_searched_at` set with no scene marks "claimed/no match".

### Search (`SearchController`)
- Laravel Scout + TNTSearch (MySQL engine); custom `tntsearch_stemmed` engine (Porter) registered in
  `AppServiceProvider`. Name search is n-gram on dirs/files; content search over extracted text and scene synopses.
- ACL, the "under this path" filter and kinds are pushed *into* the index query. `Search\FilterableSqliteEngine`
  (configured as `scout.tntsearch.engine`) stores each file/text document's directory ID and kind in a `document_meta`
  table in the index (from `__directory_id`/`__kind` keys in `toSearchableArray()`, so indexes need `scout:import`, not
  `tntsearch:import`). `Search\SearchScope` selects the allowed *directories* in SQL (ACL rules only target directories);
  the engine joins a `Search\DocumentFilter` against the doclist via temp tables, so the per-term `maxDocs` cap applies
  within the allowed set. Never enumerate allowed file IDs per request (16s on MariaDB at prod scale). Skipped when
  unrestricted. The PHP ACL check after the query stays as the authoritative backstop, with Phabricator-style
  "overheating" (`SearchOverheated`). Index files live in `storage/*.index*` (gitignored). Tests: `SearchTest`.

### Models / data
`IndexedDirectory` (self-referential tree via `parent_id`, unique `path`), `IndexedFile` (inode, mtime, size, kind,
mime, `media_info`, thumbnail/filmstrip uuids, `fingerprint_id`), `FileFingerprint`, `IndexedFileText`,
`Stash{Scene,Performer,Studio,Tag,TagCategory}`, `AccessControlEntry`, `User`. Custom casts in `app/Casts`
(`AsMediaInfo`, `BinaryToHex`, `Compressed`). Migrations are hand-maintained; latest moves the hashes and scene match into `file_fingerprints`.

## Environment

- Dev: macOS, Homebrew PHP 8.4, ffmpeg/ffprobe on PATH. Required PHP exts: dom, enchant, fileinfo, gd, zlib, ffi (vips).
- Prod: Fedora, Nginx + PHP as `apache`, MariaDB, Valkey, systemd units in `deployment/` (Horizon + scheduler timer),
  FFmpeg with CUDA filters. Paths like `/usr/bin/tesseract` are prod-absolute; tests fake `Process`.
- Clockwork profiler is wired in (`app('clockwork')->event(...)->run(...)` around expensive steps).
- Tests: Feature tests seed `indexed_directories` manually for `resources/test-media/valid/child`; use
  `$this->withoutVite()` and `config(['cache.stores.apc.driver' => 'array'])`. Coverage is thin — add tests with fixes.
