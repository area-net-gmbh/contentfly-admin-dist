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

        $widget = self::widgetOf($this->app[EntityView::CONTAINER_KEY]->formFields($name), $property);

        if ($widget === null) {
            return new JsonResponse(array(), 404);
        }

        $query = trim((string) $request->query->get('q', ''));

        if ($query === '') {
            return new JsonResponse(array());
        }

        $result = $this->app[RelationOptions::CONTAINER_KEY]->search($widget, $query, $request);

        return new JsonResponse($result === null ? array() : $result['options']);
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
