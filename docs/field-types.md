# Field types and widgets

Two questions this file answers: which input element a field of the schema becomes, and how a
project replaces that element with its own.

## Which widget a field type becomes

The core declares 21 type aliases. They map onto **15** widget keys — several types share an
element, which is intended and not a gap in the table:

| Core type | Widget key | Notes |
|---|---|---|
| `boolean` | `boolean` | |
| `integer` | `number` | |
| `decimal` | `number` | |
| `float` | `number` | |
| `string` | `text` | A `length` in the schema is passed through as `maxlength`. |
| `textarea` | `textarea` | |
| `json` | `json` | |
| `datetime` | `datetime` | |
| `time` | `time` | |
| `select` | `select` | Renders as a plain value when the schema carries no options. |
| `join` | `entity` | |
| `radio` | `radio` | Carries a group id, not an option list — see below. |
| `virtualjoin` | `entitylist` | Computed by the core: always read-only, never written. |
| `i18npermissions` | `permissionmatrix` | On `PIM\Group` only. |
| `file` | `file` | The target is `PIM\File` by definition, so `accept` is the wildcard `*`. |
| `multifile` | `filelist` | As above. |
| `multijoin` | `entitylist` | |
| `onejoin` | `entity` | |
| `checkbox` | `checkboxgroup` | Carries a group id — see below. |
| `joinbidirectional` | `entitylist` | Spelled lower case, as the core spells it. |
| `permissions` | `permissionmatrix` | On `PIM\Group` only. |

Aliases are the core's own spelling, lower case throughout: `joinbidirectional`, not
`joinBidirectional`.

### A type without a widget

A field whose schema entry carries no type, or a type absent from the table, resolves to
**`readonly`**: the value is shown and never offered for editing. That is a deliberate outcome
rather than a failure, and the admin names the type it could not place instead of rendering a
silent box.

### Grouped widgets

`radio` and `checkboxgroup` receive a **group id** instead of an option list. Their options are
fetched separately rather than being inlined per field, so a template for them renders a group
reference, not a list of values.

### Three keys that no type maps to

The package owns 18 widget keys in total, and three of them are never the result of the table:

- **`readonly`** — the fallback above, and the second candidate for any package key whose
  fragment is missing.
- **`rte`** — a rich-text editor over a `textarea`. It is not inferred from a type; it is asked
  for in the field shaping. A widget a project registers beats it.
- **`search`** — the list's search box rather than a field widget. It shares the key space and
  could be shadowed just as well, which is why it is reserved.

One mapped key deliberately ships without a fragment: **`permissionmatrix`**. The resolver can
produce it, so the key is taken, and a field of that key renders as a plain value until a
fragment exists.

## How the widget is resolved

Four steps, **first match wins**:

1. **By field** — a widget registered for this entity and this property.
2. **By type** — a widget registered for this field's type alias.
3. **Inference** — the table above.
4. **Fallback** — `readonly`.

The field beats the type, which is the point of having both: register one element for all your
date fields and a different one for the single date field that is special, without taking the
first registration back.

An override replaces **the element, never the facts about the field**. `maxlength`, `options`
and a group id follow the *inferred* widget, so your own text widget on a `string(50)` still
gets the 50 passed to it. A computed `virtualjoin` stays read-only whatever you register.

## Replacing a widget with your own

Three things have to line up. Any one of them missing is the usual cause of "my widget does
nothing".

**1. Point `widgetRoot` at your fragment directory.** It has to be absolute:

```php
'widgetRoot' => __DIR__ . '/widgets',
```

A relative path is rejected, because it would be read against the working directory — which is
not the same for a web request and a console run.

**2. Register the key in the `widgets` callback.** That callback is the one moment a
registration is guaranteed to be in time; the registry is sealed afterwards and a later
registration raises instead of quietly doing nothing.

```php
'widgets' => static function (\Areanet\ContentflyAdmin\View\WidgetRegistry $registry): void {
    $registry->registerField('Offer', 'rating', 'custom-stars'); // one field
    $registry->registerType('decimal', 'custom-slider');         // every field of a type
},
```

Your own keys **must** carry the prefix `custom-` and otherwise hold lower-case letters, digits
and hyphens. The prefix is what keeps a project widget from silently replacing one of the
package's; a key without it is refused, and the message lists the keys the package owns.

**3. Put the fragment where the key says.** The lookup goes through a Twig **namespace**, not a
second search path:

```
<widgetRoot>/custom-stars.html.twig   →   @project/custom-stars.html.twig
```

Because it is a namespace, `@project/x.html.twig` and `widget/x.html.twig` can never mean the
same file — a project fragment is reachable without anything of the package becoming
unreachable, and there is no precedence rule to remember.

One asymmetry worth knowing: a **package** key whose fragment is missing falls back to
`readonly`, because that is a decided outcome. A **project** key gets no fallback. A registered
widget whose fragment is missing is a mistake in the project, and Twig naming the template it
looked for is the message — the loud failure is the cheaper one, since the silent one looks like
a working page.

## Fragments to copy from

The package ships 18 files under `templates/widget/`: one per package key with a fragment, plus
`_label.html.twig`, which is the shared label and is included by the others rather than being a
widget itself. Reading the fragment of the key you are replacing is the fastest way to see which
variables a widget receives.

Every definition handed to a fragment carries `widget`, `type`, `required`, `readonly`,
`default` and `overridden`. The keys only some widgets need — `maxlength`, `options`, `group`,
`target`, `sortable`, `through` — are present only where they apply, so a fragment never has to
tell "absent" from "empty".
