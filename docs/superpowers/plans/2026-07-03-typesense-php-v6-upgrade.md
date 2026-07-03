# typesense-php v6 Upgrade Implementation Plan (Phase 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the package to `typesense/typesense-php ^6.0` (Typesense server 30.x) and expose the v6-only global resources (`synonymSets`, `curationSets`, `analyticsV1`) on the standalone client.

**Architecture:** The v6 client is a **superset** of v5.2 — every accessor the package already uses (`Collection::getSynonyms/getOverrides`, `Analytics::rules/events`, all `Client::get*`) still exists in v6.0.0 (verified against the v6.0.0 source). So the bump is additive: no logic changes to the Scout engine or `src/Typesense.php`. We add three thin accessors to `TypesenseConnection` and update dependency, docs, and CI.

**Tech Stack:** PHP 8.4, `typesense/typesense-php ^6.0` (v6.0.0), Typesense server 30.2, PHPUnit `^11.5|^12.0`.

## Global Constraints

- Bump `typesense/typesense-php` from `^5.0` to **`^6.0`** in `composer.json`; regenerate `composer.lock` to v6.0.0.
- Requires **Typesense server 30.x** at runtime — update CI + docs to reflect this.
- Do **not** change the logic of `src/Typesense.php`, `src/Engines/TypesenseEngine.php`, `src/Mixin/BuilderMixin.php`, or any Phase-1 `src/Standalone/*` file except `TypesenseConnection.php` (adds 3 methods) and `TypesenseStandaloneFacade.php` (adds 3 docblock lines). Their public behavior is unchanged; the v6 client is method-compatible.
- New accessors on `Siberfx\Typesense\Standalone\TypesenseConnection` return the **native** v6 resources: `synonymSets(): \Typesense\SynonymSets`, `curationSets(): \Typesense\CurationSets`, `analyticsV1(): \Typesense\AnalyticsV1`.
- Tests run under plain `PHPUnit\Framework\TestCase`, no booted Laravel container. Unit tests are network-free (construct client offline, assert `instanceof`). Integration tests extend `Siberfx\Typesense\Tests\Integration\IntegrationTestCase` and skip cleanly when no server is reachable.
- phpunit.xml has `failOnRisky` + `failOnWarning` true — every test must assert something.
- Tooling on this machine: run composer via **`composer.bat`**; run phpunit via **`php.bat vendor/phpunit/phpunit/phpunit`** (plain `php`/`composer` are not on the Git Bash PATH).
- Commit after each task with the repo's gitmoji-style messages.

## Reference: verified v6.0.0 facts

- New `Client` accessors (absent in v5.2): `getSynonymSets(): SynonymSets`, `getCurationSets(): CurationSets`, `getAnalyticsV1(): AnalyticsV1`.
- `SynonymSets`: `upsert(string $name, array $config): array`, `retrieve(): array`, ArrayAccess `[name]` → `SynonymSet`. Server-30 body: `['items' => [['id' => 'coats', 'synonyms' => ['blazer','coat','jacket']]]]`.
- `CurationSets`: `upsert(string $name, array $config): array`, `retrieve(): array`, ArrayAccess `[name]` → `CurationSet`. Server-30 body: `['items' => [['id' => 'promo', 'rule' => ['query' => 'apple', 'match' => 'exact'], 'includes' => [['id' => '422', 'position' => 1]], 'excludes' => [['id' => '287']]]]]`.
- `AnalyticsV1`: `rules(): AnalyticsRulesV1`, `events(): AnalyticsEventsV1`.
- Still present in v6 (so existing wrapper is safe): `Collection::getSynonyms(): Synonyms`, `Collection::getOverrides(): Overrides`, `Analytics::rules()`, `Analytics::events()`.

## File Structure

| File | Responsibility |
|---|---|
| `composer.json` (modify) | Bump the client constraint to `^6.0`. |
| `composer.lock` (regenerate) | Pin v6.0.0. |
| `src/Standalone/TypesenseConnection.php` (modify) | Add `synonymSets()`, `curationSets()`, `analyticsV1()`. |
| `src/Standalone/TypesenseStandaloneFacade.php` (modify) | Add `@method` lines for the three new accessors. |
| `tests/Unit/Standalone/TypesenseConnectionV6Test.php` (create) | Assert the three accessors return the native v6 resources. |
| `tests/Integration/StandaloneV6IntegrationTest.php` (create) | Live-server lifecycle for global synonym & curation sets. |
| `README.md` (modify) | Update version note; add a "v6 global sets" example + table rows. |
| `CHANGELOG.md` (modify) | Log the v6 bump + new accessors. |
| `.github/workflows/*.yml` (modify) | Bump the Typesense server image to 30.2. |

---

### Task 1: Bump the dependency to v6 and prove compatibility

**Files:**
- Modify: `composer.json` (the `typesense/typesense-php` constraint)
- Regenerate: `composer.lock`

**Interfaces:**
- Produces: an installed `typesense/typesense-php` v6.0.0. No code symbols.

- [ ] **Step 1: Edit the constraint**

In `composer.json`, change:

```json
        "typesense/typesense-php": "^5.0"
```

to:

```json
        "typesense/typesense-php": "^6.0"
```

- [ ] **Step 2: Update the lock file to v6**

Run: `composer.bat update typesense/typesense-php --with-all-dependencies`
Expected: Composer resolves and installs `typesense/typesense-php (v6.0.0)` (and any updated transitive deps). No errors.

- [ ] **Step 3: Confirm the installed version**

Run: `grep -A1 '"name": "typesense/typesense-php"' composer.lock | head -2`
Expected: shows `"version": "v6.0.0"`.

- [ ] **Step 4: Run the FULL suite to prove compatibility**

Run: `php.bat vendor/phpunit/phpunit/phpunit`
Expected: `OK, but some tests were skipped!` — all existing unit tests pass on the v6 client (this proves the Scout wrapper and Phase-1 standalone code are method-compatible with v6); integration tests skip (no local server). If any UNIT test fails, a real v6 breakage exists — stop and report it (do not paper over it).

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock
git commit -m ":arrow_up: Bump typesense/typesense-php to ^6.0 (server 30.x)"
```

---

### Task 2: Expose the v6-only accessors on `TypesenseConnection`

**Files:**
- Modify: `src/Standalone/TypesenseConnection.php`
- Modify: `src/Standalone/TypesenseStandaloneFacade.php`
- Test: `tests/Unit/Standalone/TypesenseConnectionV6Test.php`

**Interfaces:**
- Consumes: `\Typesense\Client` (now v6), which has `getSynonymSets()`, `getCurationSets()`, `getAnalyticsV1()`.
- Produces on `TypesenseConnection`:
  - `synonymSets(): \Typesense\SynonymSets`
  - `curationSets(): \Typesense\CurationSets`
  - `analyticsV1(): \Typesense\AnalyticsV1`

- [ ] **Step 1: Write the failing test**

Construction is network-free, so this runs offline.

```php
<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Typesense\AnalyticsV1;
use Typesense\Client;
use Typesense\CurationSets;
use Typesense\SynonymSets;

class TypesenseConnectionV6Test extends TestCase
{
    private function connection(): TypesenseConnection
    {
        $client = new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);

        return new TypesenseConnection($client);
    }

    public function test_exposes_global_synonym_sets(): void
    {
        $this->assertInstanceOf(SynonymSets::class, $this->connection()->synonymSets());
    }

    public function test_exposes_global_curation_sets(): void
    {
        $this->assertInstanceOf(CurationSets::class, $this->connection()->curationSets());
    }

    public function test_exposes_analytics_v1(): void
    {
        $this->assertInstanceOf(AnalyticsV1::class, $this->connection()->analyticsV1());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php.bat vendor/phpunit/phpunit/phpunit tests/Unit/Standalone/TypesenseConnectionV6Test.php`
Expected: FAIL — `Call to undefined method Siberfx\Typesense\Standalone\TypesenseConnection::synonymSets()`.

- [ ] **Step 3: Add the three accessors**

In `src/Standalone/TypesenseConnection.php`, add these imports beside the other `use Typesense\...;` lines:

```php
use Typesense\AnalyticsV1;
use Typesense\CurationSets;
use Typesense\SynonymSets;
```

Then add these methods in the "Admin passthroughs" area (e.g. right after `analytics()`):

```php
    /**
     * Global synonym sets (Typesense v6 / server 30+). Shareable across
     * collections via the collection's `synonym_sets` field.
     */
    public function synonymSets(): SynonymSets
    {
        return $this->client->getSynonymSets();
    }

    /**
     * Global curation sets (Typesense v6 / server 30+). Shareable across
     * collections via the collection's `curation_sets` field.
     */
    public function curationSets(): CurationSets
    {
        return $this->client->getCurationSets();
    }

    /**
     * Legacy (v1) analytics surface, alongside the reworked analytics() in v6.
     */
    public function analyticsV1(): AnalyticsV1
    {
        return $this->client->getAnalyticsV1();
    }
```

- [ ] **Step 4: Add the facade docblocks**

In `src/Standalone/TypesenseStandaloneFacade.php`, add these lines to the class `@method` docblock:

```php
 * @method static \Typesense\SynonymSets  synonymSets()
 * @method static \Typesense\CurationSets curationSets()
 * @method static \Typesense\AnalyticsV1  analyticsV1()
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php.bat vendor/phpunit/phpunit/phpunit tests/Unit/Standalone/TypesenseConnectionV6Test.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Standalone/TypesenseConnection.php src/Standalone/TypesenseStandaloneFacade.php tests/Unit/Standalone/TypesenseConnectionV6Test.php
git commit -m ":sparkles: Expose v6 global synonym/curation sets & analyticsV1"
```

---

### Task 3: Integration coverage for global synonym & curation sets

**Files:**
- Create: `tests/Integration/StandaloneV6IntegrationTest.php`

**Interfaces:**
- Consumes: `IntegrationTestCase` (`$this->client` healthy), `TypesenseManager`, `TypesenseConnection` (with the Task-2 accessors).

- [ ] **Step 1: Write the integration test**

Skips cleanly with no server (inherited). Requires server 30+ to pass (CI runs 30.2 after Task 4). Cleans up its own sets.

```php
<?php

namespace Siberfx\Typesense\Tests\Integration;

use Siberfx\Typesense\Standalone\TypesenseManager;

class StandaloneV6IntegrationTest extends IntegrationTestCase
{
    private function connection()
    {
        return TypesenseManager::fromConfig([
            'default' => 'default',
            'connections' => [
                'default' => [
                    'api_key' => getenv('TYPESENSE_API_KEY') ?: 'xyz',
                    'nodes' => [[
                        'host' => getenv('TYPESENSE_HOST') ?: 'localhost',
                        'port' => getenv('TYPESENSE_PORT') ?: '8108',
                        'protocol' => getenv('TYPESENSE_PROTOCOL') ?: 'http',
                    ]],
                    'connection_timeout_seconds' => 2,
                ],
            ],
        ])->connection();
    }

    private function deleteSynonymSet(string $name): void
    {
        try {
            $this->client->getSynonymSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }

    private function deleteCurationSet(string $name): void
    {
        try {
            $this->client->getCurationSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }

    public function test_global_synonym_sets_lifecycle(): void
    {
        $conn = $this->connection();
        $name = 'standalone_syn';
        $this->deleteSynonymSet($name);

        $conn->synonymSets()->upsert($name, [
            'items' => [
                ['id' => 'coats', 'synonyms' => ['blazer', 'coat', 'jacket']],
            ],
        ]);

        $all = $conn->synonymSets()->retrieve();
        $this->assertNotEmpty($all);

        $one = $conn->synonymSets()[$name]->retrieve();
        $this->assertSame($name, $one['name'] ?? $name);

        $this->deleteSynonymSet($name);
    }

    public function test_global_curation_sets_lifecycle(): void
    {
        $conn = $this->connection();
        $name = 'standalone_cur';
        $this->deleteCurationSet($name);

        $conn->curationSets()->upsert($name, [
            'items' => [
                [
                    'id'       => 'promo',
                    'rule'     => ['query' => 'apple', 'match' => 'exact'],
                    'includes' => [['id' => '422', 'position' => 1]],
                    'excludes' => [['id' => '287']],
                ],
            ],
        ]);

        $all = $conn->curationSets()->retrieve();
        $this->assertNotEmpty($all);

        $this->deleteCurationSet($name);
    }
}
```

- [ ] **Step 2: Run the integration test**

Run (no server): `php.bat vendor/phpunit/phpunit/phpunit tests/Integration/StandaloneV6IntegrationTest.php`
Expected: SKIPPED — "Typesense server not available" (clean skip, no risky/warning).

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/StandaloneV6IntegrationTest.php
git commit -m ":white_check_mark: Add integration tests for v6 global synonym/curation sets"
```

---

### Task 4: Update docs and CI for v6 / server 30

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `.github/workflows/*.yml` (the Typesense service/server version)

**Interfaces:** none (docs + CI only).

- [ ] **Step 1: Update the README version note**

In `README.md`, replace the standalone section's version note:

```markdown
> [!NOTE]
> This standalone client targets `typesense/typesense-php ^5` (server v29+).
> Global synonym sets, global curation sets, and the analytics v1/v2 split
> arrive with the planned v6 upgrade and aren't exposed yet — reach for the
> `client()` escape hatch if you need them today.
```

with:

```markdown
> [!NOTE]
> This standalone client targets `typesense/typesense-php ^6` (server **v30+**).
> That unlocks the global resources below; on older servers, pin the client to
> `^5` and use the `client()` escape hatch instead.

### Global synonym & curation sets (v6)

Typesense v30 promotes synonyms and curation to **shareable, top-level
resources**, reachable directly from the connection:

```php
$c = TypesenseDirect::connection();

// Global synonym set (link to a collection via its `synonym_sets` field)
$c->synonymSets()->upsert('clothing', [
    'items' => [
        ['id' => 'coats', 'synonyms' => ['blazer', 'coat', 'jacket']],
    ],
]);

// Global curation set (link via a collection's `curation_sets` field)
$c->curationSets()->upsert('promos', [
    'items' => [[
        'id'       => 'apple-promo',
        'rule'     => ['query' => 'apple', 'match' => 'exact'],
        'includes' => [['id' => '422', 'position' => 1]],
        'excludes' => [['id' => '287']],
    ]],
]);

// Legacy analytics surface (alongside analytics())
$c->analyticsV1()->rules()->retrieve();
```
```

- [ ] **Step 2: Add the new accessors to the README API summary table**

In the "API summary" table, update the Admin row to include the v6 globals:

```markdown
| Admin | `aliases`, `presets`, `stopwords`, `stemming`, `analytics`, `analyticsV1`, `synonymSets`, `curationSets`, `conversations`, `nlSearchModels` |
```

- [ ] **Step 3: Add a CHANGELOG entry**

Under `## [Unreleased]`, add to `### Added`:

```markdown
- v6 global resources on the standalone client: `TypesenseDirect::synonymSets()`,
  `curationSets()`, and `analyticsV1()` (Typesense server 30+).
```

and add to `### Changed`:

```markdown
- Upgraded `typesense/typesense-php` to `^6.0`; the package now targets
  Typesense server **30.x**. CI runs against the `typesense/typesense:30.2`
  image.
```

- [ ] **Step 4: Bump the Typesense server image in CI**

Find the current server version in the workflows:

Run: `grep -rn 'typesense/typesense:' .github/workflows`
Expected: one or more references to `typesense/typesense:27.1` (or similar).

Replace every `typesense/typesense:<old>` with `typesense/typesense:30.2` in the workflow files. If the version appears as a bare env/matrix value (e.g. `TYPESENSE_VERSION: 27.1`), update that to `30.2` too.

- [ ] **Step 5: Sanity-check the workflow files still parse**

Run: `grep -rn '30.2' .github/workflows`
Expected: the server image/version now reads `30.2`; no stray old version remains (re-run the Step-4 grep for the old version and confirm no matches).

- [ ] **Step 6: Commit**

```bash
git add README.md CHANGELOG.md .github/workflows
git commit -m ":memo: Document v6 global sets; run CI against Typesense 30.2"
```

---

## Self-Review

**Spec coverage (vs the design doc's Phase 2):**
- Bump `typesense/typesense-php` to `^6.0`, target server 30.x → Task 1, Task 4. ✓
- Audit `src/Typesense.php` for v6 breakages → done during planning: v6 keeps `getSynonyms/getOverrides` and `Analytics::rules/events`, so **no wrapper changes are needed** (Task 1's passing suite confirms it). ✓
- Expose v6-only `synonymSets`, `curationSets`, `analyticsV1` on `TypesenseConnection` → Task 2. ✓
- Refresh README + CHANGELOG → Task 4. ✓
- (The `typesense-standalone` skill's version table lives outside this repo and is out of scope for this plan.)

**Placeholder scan:** No TBD/TODO; every code step has complete code. The only intentionally-open step is Task 4 Step 4 (bump whatever old server version the grep finds) — bounded by the exact grep + replace instructions. ✓

**Type consistency:** `synonymSets()/curationSets()/analyticsV1()` return types (Task 2) match the `instanceof` assertions (Task 2 test) and the integration usage (Task 3). Server-30 request bodies in Task 3 match those documented in Task 4's README example. ✓

## Risks / notes
- The existing `AdminIntegrationTest` exercises per-collection synonyms/overrides. These endpoints remain in the v6 client (methods present) and server 30 retains them for compatibility, so CI should stay green; if server 30.2 has removed a specific endpoint, that surfaces as a CI integration failure to address separately.
- `composer update` may bump transitive `php-http/*` packages; the passing unit suite in Task 1 Step 4 is the guard.
