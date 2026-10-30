# Extending the admin

Four stages, in the order you will meet them. Each costs more than the one before, and the jump
from stage 1 to stage 2 is the jump from configuration to code.

Stage 3 provides exactly three things: a route plus a menu or button entry, layout plus
authentication plus permission check, and access to the data layer. Everything else is your
page's own business, and that is deliberate — the smaller the contract, the longer it holds.

Everything is registered in one place, `custom/app.php`, in the options of the `AdminManager`.
There is **no autodiscovery**: you name your classes, class by class. The admin does not scan a
directory, because registration has to be complete before the routes are mounted, and that is
the one moment this package promises not to touch the filesystem.

## Stage 1 — Shaping fields with attributes

No code, and it covers most of what a project needs: label, order, tabs, read-only, hiding a
field, declaring it mandatory. Put `#[Admin]` on the property; it is optional per property and
per argument. The arguments and what omitting them means are in
[the README](../README.md#shaping-the-fields).

Two additions to what the README says:

- **`rte: true`** turns a `textarea` into a rich-text editor. It is the one argument that selects
  an input element rather than describing the field, and a widget you register in stage 2 beats
  it.
- **For entities you cannot annotate** — the core's own `File`, `Group`, `Tag` and `Folder`, or
  anything from a third-party package — use the `ui` option instead. It carries the same
  shaping per entity, keyed by entity name, with `label`, `list`, `tabs` and `widgets`. Whether
  the names you use exist is checked against the schema on the first render, and the message
  names the unknown entity or field.

## Stage 2 — Your own field widget

When an element itself has to be different: a colour picker, a map, a slider. Which type becomes
which element, and the full set of rules for replacing one, is
[docs/field-types.md](field-types.md). The three steps in short:

```php
// custom/app.php
$app['adminManager'] = new \Areanet\ContentflyAdmin\AdminManager($app, [
    'widgetRoot' => __DIR__ . '/widgets',
    'widgets'    => static function (\Areanet\ContentflyAdmin\View\WidgetRegistry $registry): void {
        $registry->registerField('Offer', 'colour', 'custom-colour-picker');
        // or for every field of a type:
        // $registry->registerType('decimal', 'custom-slider');
    },
]);
```

Your key must carry the prefix `custom-`. The fragment then goes to
`<widgetRoot>/custom-colour-picker.html.twig`, where the admin finds it as
`@project/custom-colour-picker.html.twig`:

```twig
{# custom/widgets/custom-colour-picker.html.twig #}
{% include 'widget/_label.html.twig' %}

<input type="color"
       id="field-{{ field.property }}"
       name="{{ field.property }}"
       value="{{ field.value is empty ? '#000000' : field.value }}"
       data-controller="custom-colour-picker"
       {%- if field.required %} required{% endif %}
       {%- if field.error %} aria-invalid="true" aria-describedby="{{ described }}"{% endif %}>
```

Including the package's `widget/_label.html.twig` beats rebuilding the label: `widget/…` stays
Twig's main namespace, so your fragment can pull in the package's parts. The fragment receives
`field` and `described` and nothing else — the key list is in
[docs/field-types.md](field-types.md).

If it needs behaviour, add a Stimulus controller beside it, named after the key:

```js
// custom/widgets/controllers/custom-colour-picker_controller.js
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    connect () { /* … */ }
}
```

## Stage 3a — Your own page

A screen that is not a table at all: a generator, an evaluation, an import.

```php
// custom/Admin/NewsletterPage.php
namespace Custom\Admin;

use Areanet\ContentflyAdmin\Attribute\AdminAction;
use Areanet\ContentflyAdmin\Attribute\AdminPage;
use Areanet\ContentflyAdmin\Page\AbstractAdminPage;
use Symfony\Component\HttpFoundation\Response;

#[AdminPage(
    route:      '/newsletter',
    label:      'Newsletter',
    group:      'Tools',
    permission: 'Offer:read',
)]
final class NewsletterPage extends AbstractAdminPage
{
    public function index(): Response
    {
        $result = $this->api->getList('Offer');
        $rows   = is_array($result) ? ($result['objects'] ?? []) : [];

        return $this->render('@project/admin/newsletter.html.twig', [
            'rows' => $rows,
            'sent' => $this->request->query->get('sent') === '1',
        ]);
    }

    #[AdminAction(method: 'POST', route: '/newsletter/send')]
    public function send(): Response
    {
        // … do the work …

        return new Response('', 303, ['Location' => '/admin/newsletter/?sent=1']);
    }
}
```

```php
// custom/app.php
'pages' => static function (\Areanet\ContentflyAdmin\Page\PageRegistry $registry): void {
    $registry->register(\Custom\Admin\NewsletterPage::class);
},
```

What the base class hands you: `$this->request` (the Symfony request), `$this->api` (the data
layer), `render()` for a page inside the admin layout, and `write()` for one write against the
core. `handle()` is final — it is the dispatcher's entry, not yours.

Six things that are easy to get wrong:

- **`route` is relative to the admin's prefix** and has a leading slash: `/newsletter` answers
  at `/<prefix>/newsletter`.
- **No placeholders in a route.** The dispatcher looks an address up rather than matching a
  pattern, so parameters travel in the query string. No dot either.
- **`index()` is required.** Any other method that should be reachable needs `#[AdminAction]`;
  without it the page refuses the call and says so.
- **The template lives under `widgetRoot`**, which is the same option as in stage 2 — the
  `@project` namespace points there. A page template therefore needs `widgetRoot` set even if
  you ship no widget at all.
- **The template extends the admin layout** and fills one block:
  `{% extends "layout.html.twig" %}` then `{% block content %}…{% endblock %}`. `title`,
  `stylesheet`, `importmap`, `menu` and `logoutUrl` are already set for you.
- **A POST carries the form token.** You do not have to know the mechanism — render the hidden
  field and you are done. The field name is deliberately not written out here:

  ```twig
  <form method="post" action="{{ resetUrl }}">
      <input type="hidden" name="{{ csrfField }}" value="{{ csrfToken }}">
      <button type="submit">Send</button>
  </form>
  ```

  Only POST is checked; a GET action is a second view of the page and changes nothing.

`permission` is optional and takes the form `<Entity>:<read|write|delete>`. The order is
permission first, then token: who the editor is does not depend on which form they arrived from.

## Stage 3b — Your own action on an existing entity

No new menu entry, but a button on a record — or a bulk action in the list. This is a different
base class from stage 3a:

```php
// custom/Admin/WordExportAction.php
namespace Custom\Admin;

use Areanet\ContentflyAdmin\Attribute\AdminRowAction;
use Areanet\ContentflyAdmin\Page\AbstractRowAction;
use Symfony\Component\HttpFoundation\Response;

#[AdminRowAction(
    entity:     'Offer',
    label:      'Export as Word',
    icon:       'download',
    bulk:       true,          // additionally a bulk action in the list
    permission: 'Offer:read',
)]
final class WordExportAction extends AbstractRowAction
{
    public function __invoke(array $ids): Response
    {
        // one .docx for a single id, a zip for several
    }
}
```

```php
// custom/app.php
'rowActions' => static function (\Areanet\ContentflyAdmin\Page\RowActionRegistry $registry): void {
    $registry->register(\Custom\Admin\WordExportAction::class);
},
```

`$this->request` and `$this->api` are available as in stage 3a; there is no `render()`, because an
action answers with whatever it produces.

Seven rules, each with its reason:

1. **`__invoke(array $ids)` is the only method you write.** No constructor, no guard clauses, no
   token comparison, no unpacking of `ids[]` — all of that is in the package. In the action, every
   action would have its own version and one of them would be wrong.
2. **One piece of code for one id and for many.** The row button and the bulk button call the same
   method. Where the difference matters it is a `count($ids)`: a `.docx` for one, a zip for
   several. Two methods would be two places that drift apart.
3. **The ids are strings, not numbers.** Record ids are UUIDs. An `(int)` cast turns every one of
   them into `0` and sets the action loose on a record that does not exist. They arrive without
   duplicates, without empty values, and never as an empty array.
4. **A row button acts on its row only**, even when something further up the list is ticked.
   Selections and row buttons sit in the same form — HTML allows no second one inside it — and a
   button submits the selection by itself. The dispatcher therefore does not read `ids[]` at all
   once a row id is present.
5. **Your `Response` arrives unchanged.** No layout, no post-processing, no rewritten headers: set
   `Content-Type` and `Content-Disposition` yourself. A return value that is not a `Response`
   cannot even be loaded — the base class's return type rejects it at class load, not at click.
6. **`permission` is `<Entity>:<read|write|delete>`**, the same format and the same parser as a
   page. Without it, the action is there for anyone who may read the entity — and that right is
   checked in any case, because without it there is no list to act from. Whoever lacks it neither
   sees the button **nor** reaches it by its address.
7. **The address comes from the class name**, not from a sixth attribute argument:
   `WordExportAction` becomes `word-export` — the short name, hyphenated at its humps, lower case,
   with a trailing `Action` removed because every one of these classes ends in it. Nobody types
   that address; it is the target of a form button. Two classes of one entity with the same short
   name are refused loudly at registration.

## The two action surfaces

They are easy to confuse, and they are not alternatives:

| | `#[AdminRowAction]` | `#[AdminAction]` |
|---|---|---|
| What it is | A whole action class on an entity | A method marker inside a page or row action |
| Applies to | A class extending `AbstractRowAction` | One method of a stage-3a or 3b class |
| Appears as | Button on a record, optionally a bulk action | Nothing by itself — you link or post to it |
| Own permission | Optional, via its `permission` argument | None of its own; it inherits the class's |

An `#[AdminAction(method: 'POST')]` route verifies the form token, like every other submit path
of the admin. It carries no permission argument on purpose: the class it sits in already declares
who may be there.

## What the contract does not provide

Stage 3 gives you a route, a layout, authentication, a permission check and the data layer.
Everything else is yours: no form builder, no validation framework, no state machine. If your
page needs one, it brings its own — which is why the contract has stayed small enough to keep.

For a record action in particular, note what you do **not** have to build: no route, no mount, no
controller, no importmap entry, no JavaScript and no CSS. The buttons attach themselves to the
list's existing multi-select.

One thing that is less than it sounds: `icon` is a **hook, not an image**. The package ships no
icon set; it emits `data-icon` plus a modifier class, and the picture comes from your own
stylesheet. The label is always written out either way, so an action without a matching icon rule
is still perfectly usable.
