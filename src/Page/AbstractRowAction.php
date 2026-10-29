<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Page;

use Areanet\ContentflyAdmin\Attribute\AdminRowAction;
use Areanet\ContentflyAdmin\Auth\PermissionView;
use Areanet\ContentflyAdmin\Controller\GuardsCoreVersion;
use Areanet\ContentflyAdmin\Controller\GuardsPermissions;
use Areanet\PIM\Classes\Api;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The base class of every project action on records — the whole of stage 3b a project knows about.
 *
 * WHAT AN ACTION IS: one class with `#[AdminRowAction]` and one `__invoke(array $ids)`. No
 * constructor, no version check, no permission check, no token check, no id parsing. The ids
 * arrive ready — free of duplicates, never empty — and whatever `Response` the method returns
 * is delivered unchanged. They are strings, as a record id is everywhere else in this package:
 * the list carries a row's `id` as `string|null` and `BulkController` dedupes on the string.
 *
 * NOT `AbstractAdminPage`, ALTHOUGH CONCEPT SECTION 9 SHOWS EXACTLY THAT. A page's base class is
 * cut for a page: its entry point runs towards an argument-less method, it assembles the layout
 * context, and it lets `#[AdminAction]` methods through. An action takes ids and hands back a
 * file. Inheriting the page would give every action machinery it never calls and would force a
 * second signature onto `handle()`. The price of the split is that the concept's snippet cannot
 * be copied verbatim, and the dev guide says so rather than letting each project find out
 * (ADR 0029 §2).
 *
 * THE RESPONSE IS NOT TOUCHED. No layout, no header rewriting, no second guess. The main case is
 * a download, and an action that sets `Content-Disposition` must find it unchanged on the wire.
 * A return value that is not a `Response` cannot reach that far: the abstract signature declares
 * the type, so PHP refuses the class at compile time instead of at the click.
 *
 * READING IS IN-PROCESS (ADR 0006); `$this->api` is the core's own `Api`, bound to this request's
 * editor. THERE IS DELIBERATELY NO `write()` here, unlike on the page base class. No action needs
 * one yet, and the epic's design rule is that the contract stays small — the smaller it is, the
 * longer it holds. Adding it later is additive; taking it back is not.
 */
abstract class AbstractRowAction
{
    use GuardsCoreVersion;
    use GuardsPermissions;

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    protected $app;

    /** The request being answered. Set by `handle()`, never before. */
    protected Request $request;

    /** The core's data layer for reading, bound to this request's editor (ADR 0006). */
    protected Api $api;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The action itself — one record or many, the same code.
     *
     * @param list<string> $ids The records to act on. Never empty: an empty selection is turned
     *                          back at the list before anything is constructed.
     */
    abstract public function __invoke(array $ids): Response;

    /**
     * The one entry point the admin's dispatcher calls.
     *
     * WHAT IS CHECKED HERE AND WHAT IS CHECKED BEFORE. This runs the two gates that are the
     * action's own: the core version, and the right the action declared for itself. Everything
     * that depends on the address rather than on the class — entity whitelist, the editor's read
     * right on that entity, the form token, the ids — is the dispatcher's, because it holds the
     * route and this class does not. The order across both is the admin's own, the one
     * `BulkController` runs for the bulk actions (ADR 0029 §5).
     *
     * THE REFUSAL IS BARE, NOT A PAGE. `Controller\GuardsPermissions` splits the two forms by
     * route and not by taste: a page route answers with the 403 page that keeps the menu, an
     * action route answers with a bare 403. This is reached by POST from a form, so a rendered
     * page would be returned into a Turbo navigation that asked for a file.
     *
     * @param list<string> $ids
     *
     * @internal Called by the admin's row-action dispatcher. Not part of the project-facing
     *           contract.
     */
    final public function handle(Request $request, array $ids): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $declared = self::declaration()->permission;

        if ($declared !== null) {
            $permission = PagePermission::parse($declared);

            if (!$permission->isGranted($this->app[PermissionView::CONTAINER_KEY])) {
                return self::accessDeniedBare();
            }

            /*
             * A WRITING ACTION ALSO NEEDS THE LANGUAGE. `PagePermission` deliberately knows
             * nothing about languages — a *page* has no concept of one, and its docblock says so.
             * An action is different: it writes, and on a translatable entity the entity right is
             * only half the question (ADR 0019 §5). The language is the one the list was showing,
             * carried into this post in its hidden `query` field, because that is the context the
             * editor acted in — the same assumption the form makes about its own language.
             *
             * ONLY FOR `write` AND `delete`. A `read` action does not write, and asking the write
             * question about it would refuse an action that only looks at something.
             */
            if ($permission->action !== PagePermission::READ
                && !$this->mayEditLanguage($permission->entity, self::carriedLanguage($request))) {
                return self::accessDeniedBare();
            }
        }

        $this->request = $request;
        $this->api     = new Api($this->app, $request);

        return $this->__invoke($ids);
    }

    /**
     * The action's own `#[AdminRowAction]`, or an exception naming the class that is missing it.
     *
     * @throws \InvalidArgumentException
     */
    private static function declaration(): AdminRowAction
    {
        $attributes = (new \ReflectionClass(static::class))->getAttributes(AdminRowAction::class);

        if ($attributes === array()) {
            throw new \InvalidArgumentException(sprintf(
                'The action "%s" extends AbstractRowAction but carries no #[AdminRowAction] attribute.',
                static::class
            ));
        }

        return $attributes[0]->newInstance();
    }
}
