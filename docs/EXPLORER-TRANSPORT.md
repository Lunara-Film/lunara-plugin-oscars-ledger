# Explorer transport and lifecycle contract

The canonical record remains `academy_awards`, with `aat_award_facts`, nominees,
entities, categories, ceremonies and reporting statistics derived from it.
`AAT_Read_API` owns public reads. `AAT_Explorer` owns the full page and all six
escaped fragment regions. No shadow `ledger_entry` database, client-side record
limit, replacement grid, or second faceting/grouping implementation is introduced.

## Static fragments

`AAT_Explorer_Store` publishes the existing `{url,title,regions}` response plus a
schema/generation/time envelope under the site's actual `wp_upload_dir()` base.
The location is `lunara-ledger/explorer/<generation>/`; payload and public manifest
filenames are content hashes. Publication writes and verifies a sibling temporary
file before atomic rename, and uses a non-blocking filesystem publication lock.
A failed render, incomplete import, unavailable uploads directory, partial write,
concurrent source change, or unsupported atomic filesystem operation leaves the
ordinary PHP transport available. No database rows are deleted or replaced.

The generation includes the live dataset stamp, label rules, existing page-store
generation, active stylesheet and its `style.css` mtime, site identity, plugin/API
schema, and a content revision. The fence is read from the options table directly
before and after rendering/publication so request-local option caching cannot
conceal a concurrent edit. Imports/reporting rebuilds, published editorial and
artwork edits, Customizer saves, and relevant URL/language settings retire the
revision. That revision also keys API and artwork answers, including manual
reporting repairs that do not replace the bundled-import stamp.

Only anonymous public GET/HEAD responses qualify. Cookie/authentication/nonce
requests bypass publication. Up to 128 current URL states are indexed in a
manifest, with a 1 MiB limit per fragment. New pages advertise only a manifest
matching the current generation. The browser rejects invalid, stale, retired or
mismatched snapshots and falls back to the ordinary fragment endpoint. The
five-minute freshness bound is explicit; an already open page is not a realtime
subscription to later edits. All arbitrary filter combinations and every pager
URL remain available through PHP regardless of static-cache capacity.

A bounded WordPress cron warmer requests six common first-page states (plain,
winners, and grouped film/person/company/category). It stops on a failed response
or its time budget. A daily collector removes expired JSON files after a full day;
short storage backoff avoids repeatedly warming an unavailable directory. Retired
generation directories and their lock files are retained; the 128-entry bound is
per current manifest, not a claim that total filesystem metadata is bounded. A
separate maintenance change can prune aged, inactive generation directories with
current-generation exclusion and locking. Files
can be regenerated from canonical data; they are not a database backup.

`aat_explorer_snapshots=off` disables static transport; the existing page-store
kill switch also disables it. Public `/wp-json/lunara-ledger/v1/status` exposes
`explorer_snapshots` readiness, generation, entry count, freshness bound and storage
backoff without filesystem paths. A stale or missing snapshot never makes the
underlying awards data unavailable.

## Lifecycle and history

`aat-ledger-explorer`, `aat-ledger-motion` and `aat-ceremony-dossier` mount through
`window.LunaraApp.register()` when the theme provides it, and otherwise work on
initial load plus `lunara:page:mount`/`lunara:page:teardown` window events.
`detail.root` scopes each lifecycle. Repeated mounts are idempotent. Cleanup removes
listeners/captions, aborts requests and cancels timers, animation frames and
observers. Motion teardown restores final counts and visible server content.

Explorer history merges existing state and updates both top-level `url` and
`lle:{base,url}`. It only owns a popstate when an attached Explorer root matches
both the incoming base pathname and exact current URL. The theme navigator owns
cross-route history, and excludes Explorer's `data-lle-nav` links and managed
forms. Deep links, modified clicks, no-JS forms, facets, all groups, pagination,
canonical/noindex handling and structured credits retain their PHP behavior.

## Verification and release boundary

Local behavior checks:

- `php tests/explorer-snapshot-runtime.php`
- `php tests/explorer-page-health-runtime.php`
- `node tests/explorer-lifecycle-runtime.js`
- `node tests/ledger-motion-lifecycle-runtime.js`
- Existing read-API, Explorer media and page-store tests.

CI runs every PHP test and every top-level JS browser test. Fixture browsers test
production scripts and real motion markup, with deterministic transport/WordPress
stubs; they do not establish deployed WordPress.com behavior.

After plugin-first deployment, the release check must confirm the expected plugin
version/health, an anonymous SSR Explorer page with no JS, all views and a later
page, the root's `data-lle-snapshot` descriptor, a matching manifest and payload
served as JSON, and static miss/error fallback. Verify uploads/CDN CORS if its
origin differs from the page. Verify import/edit invalidation against the current
generation and the theme lifecycle during enter/filter/back/leave navigation.

Plain JSON is the portable artifact. Writing a `.gz` sibling does not configure
WordPress.com or a CDN, so this implementation does not claim it does. Inspect
actual Content-Type, Content-Encoding, cache headers and browser delivery after
deployment; retain dynamic fallback if the host cannot serve these files or
support their filesystem semantics. Measure cold SSR and warm filter latency on
a named device/network before claiming a speed gain; there is no unmeasured
20 ms hydration promise. The static cache optimizes repeated states, not every
possible state, and it is independently switchable.
