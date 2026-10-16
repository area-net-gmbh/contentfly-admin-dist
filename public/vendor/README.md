# Vendored browser modules

Third-party ES modules, committed as files on purpose: the admin ships **no** npm build and no
bundler, so what the browser loads has to be in the package. The reason that decision is worth
keeping is in `an_project/docs/adr/0007-*` of the development repository — the short version is
that the previous generation's build chain outlived the willingness to maintain it.

| File | Library | Version | Licence |
|---|---|---|---|
| `stimulus-3.2.2.js` | [Stimulus](https://github.com/hotwired/stimulus) | 3.2.2 | MIT |
| `turbo-8.0.23.js` | [Turbo](https://github.com/hotwired/turbo) | 8.0.23 | MIT |

Both are the libraries' own single-file ESM builds (`dist/stimulus.js`,
`dist/turbo.es2017-esm.js`), copied unmodified.

## Updating one of them

1. Fetch the release tarball from the npm registry and take the ESM build out of its `dist/`.
2. Save it here under the new version in the file name — the version is part of the name so that
   a leftover second copy of a library is visible in the network tab instead of hiding behind an
   identical URL.
3. Point `Asset\Importmap::MODULES` at the new file name and delete the old one.

No install step and no `package.json` is involved, and none should be added.
