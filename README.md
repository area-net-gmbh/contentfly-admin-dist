# Contentfly Admin — `areanet/contentfly-admin`

A schema-driven administration UI for Contentfly. The package reads `GET /api/schema` and
renders lists, forms and trees from it; it is a pure API consumer and changes nothing in the
core. Server-rendered Twig, with Turbo for navigation and Stimulus for the interactive
widgets — no npm build.

## What `1.0` deliberately does not do

Five things are missing on purpose. They are listed here so you can tell a decision from a gap
before you adopt the package — and so you do not wait for something that was never planned.

| Not in `1.0` | Why | Where it sits instead |
|---|---|---|
| **No user, group or permission management** | Permissions are *enforced* from the first release; *maintaining* them is a different job. The admin reads what the core has stored and never keeps a second set — so a management screen here would be a screen over the core's tables, and a rarely used one. | Users and groups are created with the core's console commands. The permission matrix is displayed read-only. A management screen is expected after `1.0`. |
| **No editorial approval workflow** | An approval step needs states and transitions per record. The core's data model carries none, so the admin would have to invent them — and every other client of the same API, the project's own frontend included, would not know about them. A state only the editor UI understands is not an approval workflow, it is a hidden field. | The core, if and when it carries record states. |
| **No content versioning** | Same shape as the approval workflow: the core stores one current state per record. Versioning is a property of how data is kept, not of the screen that edits it. | The core. |
| **No dedicated translation UI** | Translatable entities and per-language permissions are honoured — the admin refuses a language the editor may not write. What is missing is a screen built *for* translating, such as source and target side by side. That is a feature, not a gap in the foundation. | Expected after `1.0`. |
| **No multi-tenancy** | One installation serves one tenant. There is no use case for anything else yet, and building for an absent one is how a package grows configuration nobody needs. | Open — unplanned rather than postponed. |

Two of these are worth separating: *after `1.0`* and *not this package's job*. The translation UI
and the management screens are the first kind. The approval workflow, versioning and
multi-tenancy are the second — they change how data is stored, which is the core's business, and
no amount of work in this package would make them real.

## Installing it in a project

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/area-net-gmbh/contentfly-admin-dist.git"
        }
    ],
    "require": {
        "areanet/contentfly-admin": "^1.0"
    }
}
```

```sh
composer require areanet/contentfly-admin:^1.0
```

The core is a dependency and comes from its own dist repository, so a project that does not
already have Contentfly installed needs that repository entry as well — see the README of
`areanet/contentfly`.

## Configuration options

The admin is wired into a project by constructing an `AdminManager` in `custom/app.php`:

```php
$app['adminManager'] = new \Areanet\ContentflyAdmin\AdminManager($app, [
    'title'    => 'Example Admin',
    'entities' => ['Offer', 'Tag'],
]);
```

Every option is optional, and the table below is also the **whitelist**: an unknown key raises an
`InvalidArgumentException` while the manager is being constructed, instead of being ignored. A
silently ignored typo looks exactly like an option that does not work.

| Option | Default | What it does |
|---|---|---|
| `prefix` | `/admin` | URL path the admin answers on. Rejected if it collides with a core route. |
| `title` | `Contentfly Admin` | The name the UI shows. May not be empty. |
| `entities` | `[]` — all | The entities to administer. Empty means every entity the schema lists, in schema order. |
| `assetPath` | `''` — served by a route | URL path of a static copy of the CSS and JS. Rejected if it lies under `prefix`, or under a path the project's `.htaccess` answers with 403. |
| `thumbnailAlias` | `pim_list` | The **one** derivative the file list previews with. `pim_list` is cut to 200x200, so table rows cannot shift because an image is portrait. |
| `thumbnailAliases` | `[]` | The derivatives a single file's own page enumerates. Empty means the detail page lists none. |
| `uploadAccept` | `''` | The upload field's `accept` attribute, for example `image/*,.pdf`. Empty means no pre-check in the browser. |
| `log` | `''` — off | Path of the diagnostic log. Empty means no file, no writes, no directory created behind anyone's back. |
| `pages` | `null` | A closure receiving a `PageRegistry` — registers your own admin pages. |
| `rowActions` | `null` | A closure receiving a `RowActionRegistry` — registers your own record and bulk actions. |
| `widgets` | `null` | A closure receiving a `WidgetRegistry` — registers your own field widgets. |
| `widgetRoot` | `''` | **Absolute** path to the directory holding your own widget fragments. |
| `ui` | `[]` | Per-entity shaping for entities you cannot annotate. Per entity: `label`, `list`, `tabs`, `widgets`. |

Six of these cost time when they are misread:

- **`entities` is a configuration boundary, not a security boundary.** It is resolved once from
  the schema and never sees the signed-in editor, so it tidies the UI and protects nothing. Who
  may read or change what is checked where the core checks it.
- **`thumbnailAlias` and `thumbnailAliases` are two different options**, not a singular and its
  plural. The first picks the thumbnail of the *listing*, the second enumerates the derivatives on
  a *file's own page*. Folding them together would make the listing's thumbnail depend on the
  order of a list maintained for the detail page.
- **`thumbnailAliases` has to be set to get anything.** Which derivatives an installation has
  cannot be read from the schema, so the default cannot be guessed — a guessed alias would produce
  a broken entry on the one page whose job is to say what exists.
- **`log` names a path and the directory must already exist.** It is not created: a package that
  creates directories outside its own tree surprises its host, and a typo would silently grow one
  instead of being reported. Rotating the file is the project's job.
- **`widgetRoot` must be absolute**, for example `__DIR__ . '/widgets'`. A relative path is
  rejected, because it would be read against the working directory — which differs between the web
  entry point and a console run, the one kind of bug that shows up in only half the ways a project
  is used. Only the shape is checked here, not whether the directory exists; a missing one
  surfaces from Twig on the first render, naming the template it looked for.
- **`ui` is shape-checked at construction, name-checked later.** Whether an entity or field name
  occurs in the schema at all is verified on the first render, because the constructor must not
  open the database. Both halves fail loudly and name the key.

## Shaping the fields

Out of the box the admin infers everything from the Doctrine mapping, and that is deliberate —
`composer require` has to be enough. The result is usable but plain: technical field names,
arbitrary order. To go from usable to good, put the package's own attribute on a property. It is
optional per property and per argument; set nothing and nothing changes.

```php
use Areanet\ContentflyAdmin\Attribute\Admin;
use Doctrine\ORM\Mapping as ORM;

class Offer extends Base
{
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Admin(label: 'Subject', showInList: true, position: 10, tab: 'Master data')]
    protected $subject;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Admin(label: 'Description', showInList: false, position: 20, tab: 'Master data')]
    protected $description;
}
```

| Argument | Effect | Omitted |
|---|---|---|
| `label` | Field label. | Humanised property name. |
| `showInList` | Whether the field becomes a list column. | The list's own fallback: the first three to five scalar fields plus the id. |
| `position` | Sort key, ascending, in list and form. Leave gaps so you can insert later. | The field keeps the order the schema delivers it in. |
| `tab` | Name of the form tab the field belongs to. | The field stays outside the tab structure. |
| `readonly` | Show the value, do not allow editing. | Editable. |
| `hide` | Keep the field out of the admin. It stays in the API and the database. | Shown. |
| `required` | Declare the field mandatory. Needed for `file`, `multifile`, `multijoin`, `onejoin`, `checkbox`, `joinbidirectional` and `permissions`, which carry no `nullable` in the schema at all. | The mapping decides. |
| `rte` | Render a `textarea` as a rich-text editor. The one argument that picks an input element rather than describing the field; a widget registered for the field beats it. | Plain textarea. |

Omitting an argument is not the same as setting it to `false`: an omitted argument leaves the
inference in charge, a stated `false` overrides it. That is why every argument defaults to
`null`.

Two things worth knowing. The values are published under a single key per field —
`properties.<field>.contentflyAdmin` — in `GET /api/schema`, so **every** client of the core
sees them, not only the admin, and they change the schema `_hash`. And if the core caches the
schema to disk (`APP_ENABLE_SCHEMA_CACHE`), a changed attribute only takes effect once that
cache file is removed.

For entities you cannot annotate — the core's own `File`, `Group`, `Tag` and `Folder`, or any
entity from a third-party package — shaping through a project-side configuration file remains
the way.

Which input element a given field type becomes, and how to replace one with your own, is
[docs/field-types.md](docs/field-types.md). Going further than attributes — your own widget, your
own page, your own action on a record — is [docs/extending.md](docs/extending.md).

## Compatibility

The admin consumes the schema, so what it really depends on is the **shape** of that schema. That
shape carries no version — the core reports neither a schema version nor an envelope version — so
what can be compared is the core's framework version, and that is what is compared.

| `areanet/contentfly-admin` | requires `areanet/contentfly` |
|---|---|
| `^1.0` | `^2.x` |

The range is read from this package's own `composer.json` at runtime rather than copied into a
constant, so there is one declaration of it and not two that drift. When an admin route is
dispatched — the login screen included — the core's `APP_VERSION` is checked against that range.
On a mismatch the admin answers HTTP 500 with a page naming both versions and the expected range,
instead of rendering a UI that works for about 90 % of the fields: a list that renders, a form
that saves four fields out of five, an error nobody can place.

Three limits, stated because a compatibility promise wider than its check is worse than no promise:

- **The check runs when an admin route is dispatched, not at bootstrap.** That is deliberate. The
  manager is constructed in every request and every console run of the host project, so refusing
  there would take the project's `/api/*` down over an editor UI it may not even use.
- **A matching version is no promise that the schema is unchanged.** A core that reshaped
  `properties` without raising its own version passes this check. The refusal messages say so
  themselves rather than implying a compatibility that was never established; closing the gap
  needs a schema version from the core.
- **The lower bound of the range is declared, not measured.** `^2.0` admits every 2.x, and
  `1.0.0` was released having been exercised against **2.5.0** — the version Composer resolves
  `^2.0` to today. Nothing establishes that 2.0.0 itself carries every core API this admin calls,
  and neither Composer nor the version check would stop an install on an older 2.x. Running on a
  core below 2.5.0 is therefore supported by declaration; if that is your case, verify the admin
  against it rather than trusting the range.

What Composer adds is a different fact: it checks the constraint at install time against package
metadata. This check compares it against the version the core actually reports while running —
which is the one that drifts, when a core is swapped in place or loaded from outside Composer's
control.

## Namespace and follow-up packages

The package maps a top-level namespace of its own onto its `src/`:

```json
{
    "autoload": {
        "psr-4": { "Areanet\\ContentflyAdmin\\": "src/" }
    }
}
```

It deliberately does **not** nest under the core's `Areanet\PIM\`, and the reason is worth
knowing if you are writing a package of your own next to Contentfly. The core maps
`Areanet\PIM\` onto its **package root** rather than onto a `src/` directory, and that mapping is
greedy: every top-level directory of the core becomes a namespace segment. A nested
`Areanet\PIM\Admin\` would in fact resolve — Composer's autoloader shortens the prefix from long
to short and returns the first file that exists — but the failure it sets up is a silent one. This
package would **shadow** a same-named core class, so a core update that adds `Admin/Foo.php` would
have no effect in any project that has this package installed: no error, no warning, decided purely
by which file happens to exist.

**Nothing is reserved in the core, and that is deliberate.** There is no entry, no placeholder and
no directory held back for this package. Reserving one would have meant a cross-repository
convention that exists only in documentation, lives in a repository this package does not control,
and stays quiet when it is broken. A top-level namespace decouples the two instead: the core may
add any directory it likes and this package is unaffected. It is collision-free without an
agreement, because PSR-4 resolves per package — the core neither knows this namespace nor needs
to.

**The pattern for follow-up packages** pairs the package name `contentfly-<capability>` with the
namespace `Areanet\Contentfly<Capability>\`:

| Composer package | Namespace |
|---|---|
| `areanet/contentfly` | `Areanet\PIM\` |
| `areanet/contentfly-admin` | `Areanet\ContentflyAdmin\` |
| `areanet/contentfly-export-word` | `Areanet\ContentflyExportWord\` |

The price is that the namespace no longer visibly sits in the core's family: what a package belongs
to is readable from its Composer name rather than from its namespace.

## This repository is generated — do not commit here

**Every tag `v*` on the development repository rewrites this one.** Whatever is committed
here by hand disappears with the next release, silently and without a conflict.

| | |
|---|---|
| Source | `github.com/area-net-gmbh/contentfly-admin`, directory `lib/contentfly-admin` |
| Built by | `tools/ci/paket-veroeffentlichen.sh` via `.github/workflows/paket.yml` |
| Contains | the package alone — no dev harness, no tests, no tooling, no backlog |

Why the split exists at all: Composer reads `composer.json` from the **root** of a
repository. In the development repository it sits in `lib/contentfly-admin`, and the root
carries the dev harness — a running Contentfly instance, without which a schema-driven UI
cannot be developed at all.

**Issues, pull requests and discussion belong in the development repository**, not here.

## License

MIT. See `LICENSE`.
