# Workflow editor QA — 2026-09-12

## Scope and visual sources

Implemented in the existing Laravel/Filament editor, not a replacement app. Source references are the user's n8n screenshots dated 2026-09-11 at 23:23:15 (vertical fields), 23:26:17 (amoCRM logo), and 23:33:23 (plain catalog rows). Existing Clever light/dark tokens and icon library are retained.

The amoCRM asset is the matching transparent blue “a”, obtained from:
https://raw.githubusercontent.com/yatolstoy/n8n-node-amocrm/master/nodes/Amocrm/amocrm_logo.svg

## Combined visual comparison

- Reference and live form were viewed together at /compare-form in the isolated preview. Reference displayed at 50% for density normalization; live iframe 1280 × 820, focused middle-pane crop.
- Reference and live catalog were viewed together at /compare-catalog. Plain rows, icon/title/subtitle hierarchy, one search field and no card frames confirmed.
- This is a scoped adaptation: Clever's catalog remains narrower and its controls taller than the cropped reference. No pixel-identical clone is claimed.
- An initial compressed-column bug was fixed by applying the three-column grid to Filament's inner schema container.
- A Blade interpolation collision rendered raw label fragments; initial expression mode and placeholder are now computed before rendering. Browser readback and a regression assertion confirm clean labels.
- Field gaps were reduced after the combined comparison. No extra field-map cards surround the standard entity fields.

## Browser checks

Passed against actual editor classes with isolated SQLite fixtures and blocked external requests:

- Desktop 1280 × 720, dark and light theme toggle.
- Fixed three-pane node modal; 800 × 700 narrow viewport retains input/parameters/output and reachable footer. Viewport override reset after QA.
- Independent schedule start: added a second rule, saved, reopened, verified daily 09:00 plus Friday 18:30.
- Reconnected condition No output to a different node by clicking the node body; Yes/No direct merge is rejected.
- Telegram catalog drilldown and node form. No auto-open when adding from catalog; no implicit graph connection.
- Detached nodes now start below the preceding output stub to avoid appearing connected.
- Single-node safe execution displays output without closing its modal or running descendants.
- Compact note opens, accepts text, and collapses.
- Condition form: vertical value/operator/value with variable mode; previous-step column has neither heading nor search.
- JavaScript field's visible label/helper removed; modal heading retained.
- Delay appears under Actions → Logic. Runtime accepts only integer 1–30 seconds, resolves variables, preserves input, and skips real waiting in safe tests.
- Workflow history no longer uses a viewport-covering fixed overlay. Account history retains Scenarios / Account history / Analytics navigation, without duplicating its header.
- Step history is visual, with selected input/output and recorded sequence.

## Automated verification

- Final focused isolated PHP suite: 94 tests, 626 assertions passed, including webhook-start synchronization and lookup regressions.
- JavaScript canvas tests: 7 passed. Delay also rejects unresolved/null values.
- Build passed. Existing Browserslist data-age warning remains.
- git diff --check passed.
- Tests cover multiple starts, per-start schedule dispatch/deduplication, manual/webhook routing, branch guards, delay, encrypted Telegram token preservation, dry-run safety, typed entity updates including zero, expressions, JavaScript isolation, history, folders and analytics.

## Boundaries

Preview: http://127.0.0.1:8091/preview — isolated test data, not the user's live account. Temporary comparison pages are QA-only and not shipped application routes. No production deployment, real amoCRM mutation, webhook installation, or real Telegram message was performed. Authenticated real-account E2E remains outside this verified scope.

Array transformation remains available in the JavaScript node; automatic downstream execution once per array item is not implemented.

Final result: passed for the local implementation scope; live integrations not verified.

## 2026-09-12 compact panels follow-up

Further input-panel simplification: collapsed object/array tree with short field labels, 28px rows, no repeated full paths or instructional footer. Container counts replace expanded JSON previews. Exact expression paths preserved including quoted keys. Browser verified nested expansion and inserting items[0].id into a condition. Focused tree tests: 6 PHP / 36 assertions and 4 JavaScript tests passed; build passed.

- Editor/debug icons moved beside history; workflow history editor link is in the same right-hand action group.
- Condition form removes redundant visible select labels and tightens field spacing, preserving accessible names and saved condition semantics.
- Input panel selects one upstream step, defaults to the latest available result, and lists compact fields. Verified insertion into a condition value switches it to variable mode.
- Reference list uses single-line copy targets, separate option drilldown, and 20-row pages instead of nested expanded lists; long expressions remain in tooltips.
- Fixed dark list hover specificity. Browser confirmed actual hovered row background rgb(62, 64, 72), not the legacy near-white background.
- Browser checked light/dark appearance, condition modal at 800×700, source selection, insertion, reference category selection, and toolbar grouping. Viewport restored afterwards.
- Focused PHP suite: 19 tests / 110 assertions passed. JavaScript suite: 10 tests passed. Build and git diff --check passed.
- QA uses a single inactive fixture row in isolated temporary SQLite; no account data or external integrations changed.

## 2026-09-12 original amoCRM entity icons

- Scope: deals, customers, tasks and catalogs. Original amoCRM navigation SVG geometry, not approximated icons. Contacts/companies and unrelated controls remain unchanged.
- Visual reference: user-provided sidebar screenshot `NSIRD_screencaptureui_uqkdBz/Снимок экрана — 2026-09-12 в 14.12.32.png` (visible in conversation). macOS denied direct file access, including elevated read; comparison instead used the matching original official assets from `https://cfcdn.kommo.com/frontend/build/67501.292ee90daf672369.css`.
- Combined comparison captured in-browser at `http://127.0.0.1:8091/compare-amo-icons`, 1280×720: official source assets beside application-rendered Blade icons, identical 64×70 slots. All four geometries match, with no cropping or stretching. Initial QA-only source-image parser truncated SVG transform parentheses; corrected and recaptured successfully.
- Actual editor checked at `http://127.0.0.1:8091/preview`, 1280×720: action catalog, query catalog, deal canvas node, light and dark themes. Icon color inherits the existing theme; no new backgrounds, typography, spacing, layout or copy changes. Icons remain legible in compact catalog rows and canvas nodes.
- Shared action/trigger cards also provide the icons for history. Read operations for catalog items and fields use the catalog icon. Non-amo nodes retain their existing icons.
- Automated result: 13 focused tests / 86 assertions passed, including mapping, fallback, SVG registration/rendering and existing node-library/canvas tests.
- Final result: passed for local icon implementation. No production deployment or real-account actions in this pass. Temporary comparison route is outside the repository and is not shipped.

## 2026-09-12 field layout, webhook listener and reconnection fixes

- Shared value input now remounts when dependent option sets change, preventing a stale Alpine visibility expression from showing both text and select controls. Native selects align vertically and no longer show an inner focus frame. Browser verified pipeline → status options with exactly one visible control; light/dark modal at approximately 860×836.
- Removed parameters heading and its subtitle; hidden legacy output-mask field removed while its stored context key remains compatible. Process-launch node types are excluded from creation without deleting saved scenario data.
- Inline variable suggestions appear after `{{`, filter actual upstream fields, accept click/keyboard and replace only the current token. Verified insertion with surrounding text preserved and suggestions from newly captured webhook body.id. Left panel remains collapsed by default.
- Root cause of noninteractive arrows: the main canvas hit area covered the SVG edge hit paths. Canvas background now passes pointer events through, cards stay interactive. Actual browser pointer drags verified condition No → JavaScript and an existing trigger connection moved from the fetch input to the No-node body. Backend replacement preserves unrelated branches and still rejects cycles / direct Yes-No merges.
- Generic webhook node opens its own settings. Separate test/working URL, start/stop waiting and received data. Tested a real HTTP POST of synthetic fixture data to the isolated local test URL: returned started=false, queued=false; polling displayed body/query/headers and stopped waiting. Signed test capture cannot invoke the workflow executor even when the workflow is active. Invalid signatures remain rejected. Latest captured data is available in downstream variable suggestions.
- Theme toggle moved from overview navigation to Filament USER_MENU_BEFORE, next to the profile and notifications. Route-scoped registration verified for the real workflow overview routes; editor keeps its own theme control. Full authenticated production header was not exercised.
- Temporary webhook QA record is inactive and exists only in `/private/tmp/workflow-editor-v3.gHxGYd/qa.sqlite`. No production data, live amoCRM writes or real Telegram messages touched. Preview is local only.
- Final result: passed for the local implementation; production deployment not performed.
- Final verification: 50 focused PHP tests / 432 assertions, 14 JavaScript tests, production asset build and git diff --check passed. Existing Browserslist age warning only.

## 2026-09-14 amoCRM widget row and automatic entity context

- Source references: user screenshots `NSIRD_screencaptureui_hXR7SQ/Снимок экрана — 2026-09-14 в 10.01.54.png` and `NSIRD_screencaptureui_7FK7uy/Снимок экрана — 2026-09-14 в 10.02.53.png`. The target is the compact native amoCRM widget row: logo, title and disclosure arrow on one line.
- Actual widget script was executed in a local amoCRM DOM harness at `http://127.0.0.1:8091/`. With one button workflow the compact row and button rendered; with an empty response the owning widget container had computed `display: none`. Browser console had no warnings or errors.
- New amoCRM actions default to context entity mode. Existing actions with a stored ID reopen in manual mode. The form shows the entity type and the last available context ID; without runtime data it states that the ID will come from the node input. Fixed-type actions cannot silently switch to a different trigger type.
- Focused verification: 32 PHP tests / 355 assertions initially; after safety additions, 24 targeted tests / 317 assertions and the expanded focused set passed. Vite production build passed. The full 340-test local run reached completion but had 10 pre-existing environment errors: five attempted the configured external PostgreSQL host and five lacked the integration fixture required by current workflow activation guards.
- Live amoCRM visual verification is blocked because native Safari computer-control permission is unavailable. Production deployment is also pending explicit approval for uploading the prepared 14-file release archive.
- Final result: passed for the local widget/runtime implementation; blocked for live-account visual QA and production installation.

## 2026-09-14 compact «Потоки» widget follow-up

- Source visual truth: `/var/folders/zc/wb0mv7rs3rx8d0kgzjsg6r000000gn/T/TemporaryItems/NSIRD_screencaptureui_B82dDM/Снимок экрана — 2026-09-14 в 10.41.52.png` (668 × 446 px). Requested deltas: rename the caption to «Потоки», use regular weight, and reduce the widget height.
- Rendered implementation: actual `manual-buttons/script.js` in the amoCRM DOM harness at `http://127.0.0.1:8091/index.html`; combined before/after view at `http://127.0.0.1:8091/compare.html`. Captured in the Codex in-app browser at 504 × 854 px. The browser capture is retained in the QA tool output; this browser surface does not expose a local screenshot path.
- State: light theme, one enabled button workflow. Source and implementation were shown together in one browser view. A focused direct view confirmed the caption and button at readable scale; no additional focused crop was needed.
- Typography: caption is «Потоки», 14 px, weight 400. Button text remains 14 px, weight 400.
- Spacing/layout: caption height 44 px, 14 px horizontal padding, 9 px gap, 28 px logo. Button height 34 px. Complete one-button widget height is 96 px.
- Colors/assets: existing Clever logo and amoCRM-compatible orange/light tokens are unchanged; no placeholder or substitute asset was introduced.
- Interaction: the button remained enabled and clickable after the density changes. Browser console reported no warnings or errors.
- Comparison history: the source showed the heavier «Сценарии» heading and taller spacing. The implementation replaced the caption, reduced weight and tightened header/list/button spacing. The combined post-fix capture showed no remaining P0/P1/P2 mismatch against the requested deltas.
- Automated verification: widget archive integrity passed; focused PHPUnit suite passed with 6 tests / 21 assertions; production archive readback matched the local SHA-256.
- Final result: passed
