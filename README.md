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
