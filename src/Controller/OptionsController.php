<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\View\EntityView;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\RelationOptions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The matches for one relation field's search box (002-007-0007).
 *
 * WHY THIS ROUTE EXISTS. A relation field offers its target records as a list, but only up to
 * `RelationOptions::LIMIT` of them; above that a list would either be cut off — hiding records
 * without saying so — or load a whole table into one page. ADR 0016 §2 settled it: above the
 * limit the server searches. This is where it searches.
 *
 * THE CLIENT NAMES A FIELD, NOT A QUERY. The address is `<entity>/<property>`, and everything
 * else — which entity the relation points at, which option group it is restricted to, which
 * property carries the label — is derived here from the schema. That is deliberate and it is the
 * security property of this endpoint: a caller cannot ask about an entity the form does not
 * offer, cannot widen the group filter of a `checkbox` field, and cannot turn the route into a
 * general query interface over the installation. The whitelist check is the same one the list
 * and the form run, for the reason `ListController` gives.
 *
 * ITS OWN MOUNT AND ITS OWN PATH SEGMENT, like the list and the form — one mount binds one
 * controller class, and the segment under the prefix is ADR 0012.
 *
 * JSON AND NOT A TURBO FRAME. The caller is a Stimulus controller that puts the matches into a
 * list it owns; a server-rendered fragment would mean the markup of the field lived in two
 * places, and the field is the one thing that has to stay in step with itself.
 */
final class OptionsController
{
    use GuardsCoreVersion;
    use GuardsPermissions;
    use ResolvesTree;

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The matches for a search term, as `[{value, label}]`.
     *
     * AN EMPTY TERM ANSWERS AN EMPTY LIST and not the first fifty records. The box exists because
     * the list was too long to show; answering the beginning of it would be the truncated list
     * this route was built to avoid, only less obviously so.
     */
    public function optionsAction(Request $request, string $entity, string $property): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];
        $name    = MenuBuilder::urlToEntity($entity);

        if (!in_array($name, $manager->getEntities(), true)) {
            return new JsonResponse(array(), 404);
        }

        if (!$this->mayRead($name)) {
            return self::accessDeniedBare();
        }

        $widget = self::widgetOf($this->app[EntityView::CONTAINER_KEY]->formFields($name), $property);

        if ($widget === null) {
            return new JsonResponse(array(), 404);
        }

        /*
         * THE TARGET'S READ RIGHT IS A SECOND QUESTION. `mayRead($name)` above asks whether the
         * editor may open *this* form; the options come from another entity, and the right on it
         * is decided there. The core asks the same question — `Api::getList()` calls
         * `Permission::isReadable()` for the target, which is why `RelationOptions` goes through
         * it in-process and not over a query of its own — but it answers a refusal as "no
         * options", and on this route that is indistinguishable from "nothing matched". So the
         * gate is stated here rather than inferred from an empty list, and it answers the 403
         * ADR 0019 §6 asks a fragment route for. The core's check stays where it is: this one is
         * in front of it, not instead of it.
         *
         * IT IS ASKED BEFORE THE TERM IS READ, so a forbidden target refuses whatever the query
         * says. The entity comes from the schema by way of `RelationOptions::targetEntity()`,
         * never from the request, and `null` means the field names no target to refuse.
         */
        $target = $this->app[RelationOptions::CONTAINER_KEY]->targetEntity($widget);

        if ($target !== null && !$this->mayRead($target)) {
            return self::accessDeniedBare();
        }

        $query = trim((string) $request->query->get('q', ''));

        if ($query === '') {
            return new JsonResponse(array());
        }

        $result = $this->app[RelationOptions::CONTAINER_KEY]->search($widget, $query, $request);

        $options = $result === null ? array() : $result['options'];

        /*
         * A NODE IS NOT OFFERED AS ITS OWN PARENT, NOR IS ANYTHING BELOW IT. `FormController`
         * takes the same ids out of the inline list; above `RelationOptions::LIMIT` there is no
         * inline list, and a large tree is exactly where a cycle is most likely to be reachable.
         * The cost of missing it is measured and total: the core writes the cycle without a word
         * and `Api::getTree2()` then answers an empty tree — the whole tree gone from the admin
         * with no error anywhere (gap L31).
         *
         * THE ID COMES FROM THE REQUEST, AND THAT IS SAFE HERE BECAUSE IT ONLY EVER *REMOVES*
         * CHOICES. A forged or stale `self` can hide a legitimate parent from one dropdown; it
         * cannot reveal a record, widen a permission or offer a node from another tree — the
         * target entity is decided in `WidgetResolver::target()` from the schema, never from
         * here (ADR 0025 §3). An id that is not in the tree removes nothing.
         */
        $self = $request->query->get('self');

        if (is_string($self) && $self !== '' && $property === self::PARENT_PROPERTY
            && $this->app[EntityView::CONTAINER_KEY]->isTree($name)) {
            $node = self::findInTree($this->treeRows($request, $name), $self);

            if ($node !== null) {
                $forbidden = self::subtreeIds($node);
                $options   = array_values(array_filter(
                    $options,
                    static function (array $option) use ($forbidden): bool {
                        return !in_array((string) ($option['value'] ?? ''), $forbidden, true);
                    }
                ));
            }
        }

        return new JsonResponse($options);
    }

    /**
     * The widget definition of one property of this form, or `null` if the form has no such field.
     *
     * `formFields()` IS THE SOURCE, NOT THE SCHEMA, and that is the point: a field the entity
     * marks `hide` is not in this list, so it cannot be searched over either. The answer for an
     * unknown property is the same 404 as for an unknown entity — telling the two apart would
     * say which fields exist.
     *
     * @param list<array<string,mixed>> $fields
     *
     * @return array<string,mixed>|null
     */
    private static function widgetOf(array $fields, string $property): ?array
    {
        foreach ($fields as $field) {
            if ($field['property'] === $property) {
                return $field['widget'];
            }
        }

        return null;
    }
}
