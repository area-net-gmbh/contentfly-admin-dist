<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Auth\FormToken;
use Areanet\ContentflyAdmin\Page\RowActionRegistry;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one dispatcher behind every project action on records — stage 3b.
 *
 * ONE MOUNT FOR ALL OF THEM, `<prefix>/rowaction/{entity}/{action}`, which is the shape `/bulk`
 * and `/form` already use (`AdminManager::mountRoutes()`). Placeholders are fine here and are
 * refused for project *pages* — not a contradiction: the page dispatcher resolves an address by
 * looking it up, and a pattern cannot be looked up, while a package mount hands the core a real
 * matcher (ADR 0028 §4 against ADR 0029 §3).
 *
 * WHAT THIS CHECKS AND WHAT THE ACTION'S BASE CLASS CHECKS. Everything that hangs on the
 * *address* is here, because only this class holds the route: the entity, the whitelist, the
 * editor's read right on it, the method, the form token and the ids. The two gates that belong
 * to the *class* — the core version and the action's own `permission` — run in
 * `Page\AbstractRowAction::handle()`. The order across both is the admin's own, the one
 * `BulkController` runs (ADR 0029 §5).
 *
 * THE ROW BUTTON'S ID WINS AND `ids[]` IS THEN NOT READ AT ALL. This is the decision the whole
 * feature turns on and it is written as a refusal, not as a precedence: the tick boxes and the
 * row buttons live in the same POST form because HTML allows no second one inside it, so a row
 * button submits every ticked id unless something stops it. A precedence rule can be inverted by
 * accident; a field that is never read cannot. Without it, pressing a row button would quietly
 * act on rows the editor did not point at — invisibly, until an action writes (ADR 0029 §4).
 *
 * REFUSALS GO BACK TO THE LIST WITH A CODE, NOT INTO AN ERROR. The codes are the ones the list
 * already knows (`ListController::report()`): `expired` for a stale token, `none` for an empty
 * selection, `unknown` for an address no action answers. Reusing them is the point — a second
 * vocabulary for the same three situations would have to be translated twice.
 */
final class RowActionController
{
    use GuardsCoreVersion;
    use GuardsPermissions;

    /**
     * The field a row button submits its own record id in.
     *
     * Deliberately not `ids[]`: it has to be distinguishable from the tick boxes, because telling
     * the two apart is the whole safeguard above.
     */
    public const ROW_FIELD = 'row';

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Answers one record action, reached by POST from the list's form.
     *
     * A GET IS NOT AN ERROR WORTH A PAGE — it is what a reload after an action or a typed address
     * produces, and the list is where the editor wanted to be. `BulkController` says the same.
     */
    public function rowActionAction(Request $request, string $entity, string $action): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];
        $name    = MenuBuilder::urlToEntity($entity);

        if (!in_array($name, $manager->getEntities(), true)) {
            return new Response('', 404);
        }

        if (!$this->mayRead($name)) {
            return self::accessDeniedBare();
        }

        $list = $manager->getPrefix().'/list/'.MenuBuilder::entityToUrl($name);

        if (!$request->isMethod('POST')) {
            return self::back($list, $request);
        }

        if (!FormToken::verify($request)) {
            return self::back($list, $request, array('bulk' => 'expired'));
        }

        $target = $this->app[RowActionRegistry::CONTAINER_KEY]->target($name, $action);

        if ($target === null) {
            return self::back($list, $request, array('bulk' => 'unknown'));
        }

        $ids = self::ids($request);

        if ($ids === array()) {
            return self::back($list, $request, array('bulk' => 'none'));
        }

        $class = $target->class;

        return (new $class($this->app))->handle($request, $ids);
    }

    /**
     * The records this call is about — one row's, or the ticked ones, never both.
     *
     * THE ROW FIELD IS ASKED FIRST AND ENDS THE QUESTION. When it is there, `ids[]` is not looked
     * at; that is the refusal ADR 0029 §4 asks for, in the one place it can be enforced.
     *
     * Ids stay strings, as they are everywhere else in this package — the list carries a row's
     * `id` as `string|null`, and `BulkController` dedupes on the string too. Casting to int here
     * would be a second opinion about what an id is.
     *
     * @return list<string>
     */
    private static function ids(Request $request): array
    {
        $row = $request->request->get(self::ROW_FIELD);

        if (is_scalar($row) && (string) $row !== '') {
            return array((string) $row);
        }

        $submitted = $request->request->all()['ids'] ?? null;

        if (!is_array($submitted)) {
            return array();
        }

        $ids = array();

        foreach ($submitted as $id) {
            if (is_scalar($id) && (string) $id !== '' && !in_array((string) $id, $ids, true)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Back to the list, carrying the view's own parameters and this call's report.
     *
     * The same shape `BulkController::back()` uses, down to stripping a stale report out of the
     * carried query — a `bulk` key from an address somebody kept would otherwise describe
     * something that did not happen here. Only the relative list address is ever redirected to;
     * the carried `query` contributes parameters, never a target.
     *
     * @param array<string,string|int> $report
     */
    private static function back(string $list, Request $request, array $report = array()): Response
    {
        $carried = array();
        parse_str((string) $request->request->get('query', ''), $carried);

        $params = $report + array_diff_key($carried, array_flip(BulkController::REPORT_KEYS));

        return new Response('', 303, array(
            'Location' => $params === array() ? $list : $list.'?'.http_build_query($params),
        ));
    }
}
