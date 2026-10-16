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

## Compatibility

The hard dependency is the **schema format**, not the PHP API. The admin checks the version
it is talking to at startup and stops with a clear message rather than rendering a UI that
works for 90 % of the fields.

| `areanet/contentfly-admin` | requires `areanet/contentfly` |
|---|---|
| `^1.0` | `^2.x` |

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
