# Plan: view `block_sqlreports` in the Moodle Mobile app

## Goal

Surface the same published report (table or chart) that the web block renders, inside the
Moodle Mobile app, using a server-side remote template. No app rebuild — the site declares the
addon and any installed app renders it.

## How the mobile app renders a block

The app discovers plugin UI from `db/mobile.php`. For a block, the relevant delegate is
`CoreBlockDelegate`. The app, when drawing the blocks region on a page it supports (Dashboard /
course), calls the plugin's server method, which returns a Mustache/Ionic template plus optional
initial data and JS. The app renders that inline where the block would sit.

Key constraint: the app only draws blocks on pages it models — primarily the **Dashboard (`my`)**
and **course** views. The site front page block region is not rendered the same way. So mobile
coverage will be a subset of `applicable_formats()` (`my` + `course-view`; `site` is out of scope).

## What we can reuse

The web block already isolates all data + access logic in `report_sql`:

- `query::get($queryid)` — load the report query object.
- `query::published_menu()` — resolve title.
- `$query->current_user_can_view_report()` — same access gate as the RB viewer.
- `$query->fetch_rows_for_viewer($rowlimit, $pagecourseid)` — per-viewer filtered rows.
- `$query->record()` — `chartmeta`, `reportid`, etc.

The mobile view method calls exactly the same path, so per-user row filtering, teacher-course
scoping, and access control behave identically to the web block. No data logic is duplicated.

## Deliverables (new files)

```
db/mobile.php                       # declare the CoreBlockDelegate addon + lang + service
db/services.php                     # expose the mobile view method as a mobile web service
classes/output/mobile.php           # the view method: build template + data
mobile/styles.css                   # (optional) mobile-only CSS, referenced from db/mobile.php
```

Plus `version.php` bump and new lang strings.

## Step 1 — `db/mobile.php`

Declare a `CoreBlockDelegate` handler pointing at a server method.

```php
$addons = [
    'block_sqlreports' => [
        'handlers' => [
            'view' => [
                'delegate' => 'CoreBlockDelegate',
                'method'   => 'mobile_block_view',
                'styles'   => [
                    'url'     => '/blocks/sqlreports/mobile/styles.css',
                    'version' => 1,
                ],
                'offlinefunctions' => [
                    'mobile_block_view' => [],
                ],
            ],
        ],
        'lang' => [
            ['pluginname', 'block_sqlreports'],
            ['norows', 'block_sqlreports'],
            ['viewfull', 'block_sqlreports'],
            ['errdata', 'block_sqlreports'],
        ],
    ],
];
```

Note: `CoreBlockDelegate` support for arbitrary blocks is limited in the app — confirm the current
app version renders custom-block addons in the target region. If the app build in use does not
draw the addon for this block instance, fall back to a **`CoreCourseOptionsDelegate`** (course tab)
and/or **`CoreMainMenuDelegate`** (main-menu entry) handler so the report is still reachable. This
is the main open risk; verify early against a real device before building out the template.

## Step 2 — `db/services.php`

The `method` must be a registered web service in the official mobile service.

```php
$functions = [
    'block_sqlreports_mobile_block_view' => [
        'classname'    => 'block_sqlreports\output\mobile',
        'methodname'   => 'mobile_block_view',
        'description'  => 'Returns the report block rendered for the mobile app',
        'type'         => 'read',
        'ajax'         => true,
        'services'     => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
```

## Step 3 — `classes/output/mobile.php`

The view method receives `$args` (contains `contextlevel`, `instanceid`/`contextid`, and the
`blockid` the app is rendering). Steps:

1. Resolve the block instance from args, load its config (`queryid`, `rowlimit`, `displaymode`,
   `title`), same fields the web block reads from `$this->config`.
2. Resolve `$pagecourseid` from the arg context (course id, or `0` when off a course — same rule as
   the web block, where `SITEID` maps to `0`).
3. `require_login` / context check, then `$query->current_user_can_view_report()`. On failure return
   an empty template (block hides), matching web behaviour.
4. `$rows = $query->fetch_rows_for_viewer($rowlimit, $pagecourseid)` inside try/catch; on
   `moodle_exception` return the `errdata` message, never raw rows.
5. Return `templates`, `otherdata`, `javascript`.

Return shape:

```php
return [
    'templates' => [
        ['id' => 'main', 'html' => self::render_template()],
    ],
    'otherdata' => [
        'rows'    => json_encode($rowsforjs),   // array of {cols:[...]} or {label,value}
        'headers' => json_encode($headers),
        'ischart' => $ischart ? 1 : 0,
        'fullurl' => $fullreporturl,
    ],
    'files' => [],
];
```

### Table rendering (primary, low risk)

Emit an Ionic table/list from `otherdata.rows`. Because the app is Angular, do **not** ship the
web block's raw author HTML into an `innerHTML`. Two safe options:

- Preferred: send **plain text cell values** (strip tags server-side) and render with
  `<core-format-text [text]="cell">`, which applies Moodle filtering/escaping. This drops the
  clickable links the web table injects, but is safe and simple.
- If links matter, keep them but render each cell via `core-format-text` so the app sanitises;
  do not use `[innerHTML]`.

```html
<ion-list *ngIf="!CONTENT_OTHERDATA.ischart">
  <ion-item *ngFor="let row of CONTENT_OTHERDATA.rows">
    <ion-label>
      <core-format-text *ngFor="let cell of row.cols" [text]="cell"></core-format-text>
    </ion-label>
  </ion-item>
  <core-empty-box *ngIf="CONTENT_OTHERDATA.rows.length === 0"
      icon="fas-table" [message]="'plugin.block_sqlreports.norows' | translate">
  </core-empty-box>
</ion-list>
<ion-button *ngIf="CONTENT_OTHERDATA.fullurl" expand="block"
    (click)="openReport(CONTENT_OTHERDATA.fullurl)">
  {{ 'plugin.block_sqlreports.viewfull' | translate }}
</ion-button>
```

### Chart rendering (secondary, higher effort)

The web block uses `$OUTPUT->render_chart()` (core/chart_builder, an AMD module). That AMD module
is **not available** in the app runtime, so the web approach does not port directly. Options,
cheapest first:

1. **Fallback to table on mobile** — if `displaymode === 'chart'`, still send rows and render the
   table. Simplest; ship this first.
2. **Server-rendered image** — render the chart to a static image/SVG server-side and return a
   `files` entry / data URI shown via `<img>`. No interactivity, but shows a real chart.
3. **Client chart lib** — bundle a small canvas chart (e.g. Chart.js) inside the template JS and
   draw into a `<canvas>` from `otherdata`. Most work; only if interactive charts are required.

Recommend shipping option 1 first, treat 2/3 as a follow-up.

## Step 4 — `mobile/styles.css` (optional)

Minor spacing / column styling. Bump the `version` in `db/mobile.php` whenever this changes, or the
app keeps the cached copy.

## Step 5 — lang + version

- Reuse existing strings (`norows`, `viewfull`, `errdata`, `pluginname`); add mobile-only strings if
  the template needs any new label.
- Bump `$plugin->version` in `version.php`.
- All strings referenced in templates via `translate` must be listed in the `lang` array of
  `db/mobile.php`, else the app can't resolve them.

## Security / privacy notes

- The mobile method **must** run the same `current_user_can_view_report()` gate and the same
  `fetch_rows_for_viewer()` per-viewer filtering as the web block — the app is just another client;
  it does not relax access control.
- Do not return raw HTML for `innerHTML`; route cell content through `core-format-text` so the app
  sanitises (equivalent to the web block's `format_text` + link hardening).
- On any data exception, return the neutral `errdata` message, never partial/raw rows.
- The web service is `type => 'read'`, no mutations, so no sesskey/offline-write concerns.

## Offline

Declaring `mobile_block_view` in `offlinefunctions` lets the app prefetch the rendered rows during
sync so the report survives offline (read-only snapshot). Acceptable for a report view. Note the
prefetched data is a point-in-time copy; document that mobile offline view may be stale.

## Testing

1. Site admin > Mobile > enable web services for mobile devices.
2. Point the app (device/emulator) at the dev site; log in.
3. Add the block to the Dashboard on web, configure a report, then open the Dashboard in the app.
4. Verify: table renders, row filtering per user matches web, `viewfull` opens the RB report,
   `norows`/`errdata` states show correctly.
5. Pull-to-refresh (or app settings > Synchronisation) to force template re-fetch after server
   changes.
6. Add a PHPUnit test for `block_sqlreports\output\mobile::mobile_block_view` asserting the
   returned structure and that access denial yields an empty/hidden result.

## Recommended order

1. `db/services.php` + `classes/output/mobile.php` returning a **table only** + `db/mobile.php`.
2. Verify the addon actually renders in the app's block region on a real device (the main risk); if
   not, switch delegate to `CoreCourseOptionsDelegate` / `CoreMainMenuDelegate`.
3. Add `viewfull` link, empty/error states, lang wiring.
4. Add chart support (image fallback first) as a follow-up.
5. Offline prefetch + PHPUnit test.
```