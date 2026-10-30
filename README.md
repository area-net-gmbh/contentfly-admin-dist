# Contentfly Admin — `areanet/contentfly-admin`

A schema-driven administration UI for Contentfly. The package reads `GET /api/schema` and
renders lists, forms and trees from it; it is a pure API consumer and changes nothing in the
core. Server-rendered Twig, with Turbo for navigation and Stimulus for the interactive
widgets — no npm build.

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
[docs/field-types.md](docs/field-types.md).

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

Two limits, stated because a compatibility promise wider than its check is worse than no promise:

- **The check runs when an admin route is dispatched, not at bootstrap.** That is deliberate. The
  manager is constructed in every request and every console run of the host project, so refusing
  there would take the project's `/api/*` down over an editor UI it may not even use.
- **A matching version is no promise that the schema is unchanged.** A core that reshaped
  `properties` without raising its own version passes this check. The refusal messages say so
  themselves rather than implying a compatibility that was never established; closing the gap
  needs a schema version from the core.

What Composer adds is a different fact: it checks the constraint at install time against package
metadata. This check compares it against the version the core actually reports while running —
which is the one that drifts, when a core is swapped in place or loaded from outside Composer's
control.

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
