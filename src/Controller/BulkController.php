<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Auth\FormToken;
use Areanet\ContentflyAdmin\Schema\Envelope;
use Areanet\ContentflyAdmin\View\EntityView;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The two actions a selection of records can be put through (002-004-0005).
 *
 * THE WRITE PATH IS THE FORM'S WRITE PATH. Both actions speak to the core by sub-request and read
 * the answer with `Envelope::read()`, which is ADR 0014 — the directly callable classes do not
 * answer in the shape an error can be read out of.
 *
 * THE TWO ACTIONS ARE NOT THE SAME KIND OF OPERATION, and the difference is the core's, not a
 * choice made here. `/api/multiupdate` wraps the whole batch in a transaction, so a failed change
 * writes nothing and there is one answer to report. A bulk delete does not exist: `/api/delete`
 * takes a single id and runs its own permission check on it (`Api.php:110-115`), so a selection
 * is N calls and a partial result is the normal case, not the exception. ADR 0018 §6 settled what
 * to do about it: carry on and report, rather than abort on the first refusal and leave the rest
 * of the selection hostage to one locked row.
 *
 * THE REPORT TRAVELS IN THE QUERY STRING, because there is nowhere else to put it. The core keeps
 * no server session (ADR 0009), so there is no flash store, and Turbo needs a 303 to a GET for a
 * form submission — the list has to be re-fetched, and whatever is to be said about the action
 * has to survive that fetch. What travels is therefore *structured*: the action, two counts and
 * the ids that failed. The sentence itself is composed in `ListController` out of those parts, so
 * nothing a crafted URL contains can become free text on the page. What such a URL *can* do is
 * show a wrong count to the person who follows it, which is the one thing this transport cannot
 * prevent and the reason the ids are shown alongside: a count without names would be the whole
 * claim.
 *
 * ITS OWN MOUNT AND ITS OWN PATH SEGMENT, like the list, the form and the options — one mount
 * binds one controller class (`AdminManager::mountRoutes()`), and the segment under the prefix is
 * ADR 0012.
 */
final class BulkController
{
    use GuardsCoreVersion;
    use GuardsPermissions;

    /** The field that says which action was pressed. */
    public const ACTION_FIELD = 'action';

    /**
     * What the report says instead of a code when the core's answer could not be read.
     *
     * NOT AN INVENTED ERROR CODE — a marker of this package, which is why it carries no
     * `contentfly_` prefix. A `ContentflyException` inside a sub-request does not always come
     * back as an envelope: with devmode on, the core's error handler answers the debug page, and
     * `Envelope::read()` has nothing to decode. The form view has the same case and names it
     * (`FieldError::UNREADABLE`) rather than leaving the editor with a refusal and no reason, and
     * a bulk report that quietly dropped the reason would read as if the core had not given one.
     */
    public const UNREADABLE = 'unreadable';

    /** Delete every selected record, one call each. */
    public const DELETE = 'delete';

    /** Set one field of every selected record, in one transactional call. */
    public const CHANGE = 'change';

    /**
     * How many failed ids the report carries at most.
     *
     * A SELECTION CAN BE A WHOLE PAGE, so the failures can be 25 ids. Naming them all would make
     * an address nobody can read and a sentence nobody finishes; naming none would leave the
     * reader with a number and no way to find out which records to look at. The report therefore
     * names the first few and says how many there were in total.
     */
    public const NAMED_FAILURES = 5;

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * One bulk action over the selected records, then back to the list they came from.
     *
     * EVERY EXIT IS A 303 TO THE LIST, including every refusal. This route renders nothing of its
     * own: it is reached by a form submission and Turbo would discard a 2xx body, so a message
     * shown here would be a page the reader never sees. The refusals differ only in what the
     * report says.
     *
     * THE TOKEN IS CHECKED BEFORE THE CORE IS TOUCHED, as in `FormController::save()`. The form
     * that carries it is rendered by the list (002-004-0004), which also sets the cookie the check
     * compares against.
     *
     * @param string $entity The entity's URL segment, e.g. `PIM-File` (ADR 0011)
     */
    public function bulkAction(Request $request, string $entity): Response
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

        /*
         * A GET ON THIS ROUTE IS NOT AN ERROR WORTH A PAGE. It is what happens when somebody
         * reloads after an action or types the address, and the list is where they wanted to be.
         */
        if (!$request->isMethod('POST')) {
            return self::back($list, $request);
        }

        if (!FormToken::verify($request)) {
            return self::back($list, $request, array('bulk' => 'expired'));
        }

        $ids = self::ids($request);

        if ($ids === array()) {
            return self::back($list, $request, array('bulk' => 'none'));
        }

        $action = $request->request->get(self::ACTION_FIELD);

        /*
         * THE ACTION'S OWN RIGHT, AND IT REPORTS RATHER THAN 403s. The read permission above
         * already answered whether this entity is the editor's business at all; this answers
         * whether *this action* is, and a missing delete right is an ordinary outcome of a form
         * post, not a broken request. So it takes the path the controller's other refusals take
         * — back to the list with a code `ListController::report()` turns into a sentence —
         * instead of a blank 403 the editor would have to navigate away from.
         *
         * The core checks the same right per record on every call (`Api.php:120-131`, `:605-615`).
         * Checking it here as well is not duplication: it keeps a selection of twenty records
         * from going out one request at a time only to be refused twenty times.
         */
        if ($action === self::DELETE) {
            if (!$this->mayDelete($name)) {
                return self::back($list, $request, array('bulk' => 'forbidden'));
            }

            return self::back($list, $request, $this->delete($request, $name, $ids));
        }

        if ($action === self::CHANGE) {
            if (!$this->mayWrite($name)) {
                return self::back($list, $request, array('bulk' => 'forbidden'));
            }

            return self::back($list, $request, $this->change($request, $name, $ids));
        }

        return self::back($list, $request, array('bulk' => 'unknown'));
    }

    /**
     * Every selected record deleted on its own, with the refusals collected.
     *
     * ONE CALL PER RECORD, AND IT DOES NOT STOP. The core has no batch delete, and each call
     * carries its own permission check — so one record the editor may not delete is an ordinary
     * outcome and must not decide the fate of the other twenty. The loop therefore runs to the
     * end and the report says what did not happen.
     *
     * THE CODE OF THE FIRST REFUSAL IS WHAT IS REPORTED, not one code per id. The core answers
     * one finding at a time (gap L20) and the refusals of a selection are almost always the same
     * one — a missing permission, or records that are already gone. Carrying a code per id would
     * make the address grow with the selection to say the same thing N times.
     *
     * @param list<string> $ids
     *
     * @return array<string,string|int>
     */
    private function delete(Request $request, string $name, array $ids): array
    {
        $done   = 0;
        $failed = array();
        $code   = null;

        foreach ($ids as $id) {
            $envelope = $this->call($request, '/api/delete', array('entity' => $name, 'id' => $id));

            if ($envelope['ok']) {
                $done++;
                continue;
            }

            $failed[] = $id;
            $code      = $code ?? self::code($envelope);
        }

        return self::report(self::DELETE, $done, $failed, $code);
    }

    /**
     * One field of every selected record, set in a single transactional call.
     *
     * THE FIELD IS CHECKED AGAINST WHAT THE ENTITY OFFERS, never taken from the request as given.
     * `EntityView::changeableFields()` is the same list the bar was rendered from, so a crafted
     * post cannot name a field the form protects, computes or hides — the same property
     * `FormController::payload()` has, and for the same reason.
     *
     * ALL OR NOTHING, BECAUSE `multiupdate` IS A TRANSACTION. A failure therefore has no partial
     * result to report: nothing was written, and the one finding the core answers with is the
     * whole story. That is the one real difference to the delete above, and ADR 0018 §5 chose it
     * for exactly this reason.
     *
     * @param list<string> $ids
     *
     * @return array<string,string|int>
     */
    private function change(Request $request, string $name, array $ids): array
    {
        $field = $request->request->get('field');
        $fields = $this->app[EntityView::CONTAINER_KEY]->changeableFields($name);
        $chosen = null;

        foreach ($fields as $candidate) {
            if ($candidate['property'] === $field) {
                $chosen = $candidate;
                break;
            }
        }

        if ($chosen === null) {
            return array('bulk' => 'field');
        }

        $submitted = $request->request->all()['value'] ?? array();
        $value     = is_array($submitted) ? ($submitted[$chosen['property']] ?? null) : null;

        if (!is_scalar($value)) {
            return array('bulk' => 'value');
        }

        $objects = array();

        foreach ($ids as $id) {
            $objects[] = array(
                'entity' => $name,
                'id'     => $id,
                'data'   => array($chosen['property'] => self::coerce($chosen['type'], (string) $value)),
            );
        }

        $envelope = $this->call($request, '/api/multiupdate', array('objects' => $objects));

        if (!$envelope['ok']) {
            return self::report(self::CHANGE, 0, $ids, self::code($envelope));
        }

        return self::report(self::CHANGE, count($ids), array(), null);
    }

    /**
     * One value in the shape the core writes it.
     *
     * A SELECT POSTS STRINGS AND THE DATABASE DOES NOT TAKE THEM. `"false"` is a non-empty string
     * and therefore true everywhere it is not converted on purpose — the form view has the same
     * problem and solves it by deriving a boolean from the presence of its checkbox
     * (`FormController::payload()`). A bulk change has no checkbox to be absent, so the two
     * spellings of the select are converted here and nowhere else.
     *
     * The number types are left to the core, which coerces them itself (`Api.php:1424`), and a
     * text stays what was typed.
     *
     * @return string|bool
     */
    private static function coerce(string $type, string $value)
    {
        if ($type !== 'boolean') {
            return $value;
        }

        return $value === 'true';
    }

    /**
     * One sub-request to the core, with its answer read as an envelope.
     *
     * THE PAYLOAD GOES IN THE REQUEST BAG, not as a JSON body — the core reads every one of these
     * endpoints out of `$request->request`, and ADR 0014 records that this is what makes the
     * sub-request work at all. The server parameters are carried over so the guarded main
     * request's `auth.user` is the one the core checks permissions against.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null}
     */
    private function call(Request $request, string $endpoint, array $payload): array
    {
        $sub = Request::create($endpoint, 'POST', $payload, array(), array(), $request->server->all());

        $response = $this->app->handle($sub, HttpKernelInterface::SUB_REQUEST);

        return Envelope::read($response->getContent(), $response->getStatusCode());
    }

    /**
     * The ids of the selection, as `ids[]` carries them (ADR 0018 §7).
     *
     * EVERY ENTRY IS A STRING OR IT IS DROPPED, and duplicates are dropped with it. The ids are
     * opaque here — whether one exists is the core's answer, not this controller's guess — but a
     * nested array or a repeated id would turn into a malformed or doubled call, and a record
     * deleted twice would report a refusal for an action that succeeded.
     *
     * @return list<string>
     */
    private static function ids(Request $request): array
    {
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
     * The report as the list's query string carries it.
     *
     * @param list<string> $failed
     *
     * @return array<string,string|int>
     */
    private static function report(string $action, int $done, array $failed, ?string $code): array
    {
        $report = array('bulk' => $action, 'done' => $done, 'failed' => count($failed));

        if ($failed !== array()) {
            $report['ids'] = implode(',', array_slice($failed, 0, self::NAMED_FAILURES));
        }

        if ($code !== null) {
            $report['code'] = $code;
        }

        return $report;
    }

    /**
     * The core's own code for a refusal, or `null` when it named none.
     *
     * A CODE AND NEVER THE TEXT. The code is a stable identifier the admin maps to a German
     * sentence of its own (`ListController`); the text is free prose from the core and would have
     * to travel through a URL, where anybody could replace it with a sentence of their choosing.
     *
     * @param array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null} $envelope
     */
    private static function code(array $envelope): ?string
    {
        $code = $envelope['error']['code'] ?? null;

        if (is_string($code) && $code !== '') {
            return $code;
        }

        return $envelope['readable'] ? null : self::UNREADABLE;
    }

    /**
     * Back to the list the selection came from, report attached.
     *
     * THE PATH IS BUILT HERE, THE PARAMETERS COME FROM THE FORM. That split is the whole security
     * property of the return address (002-004-0004): the hidden `query` field carries only a query
     * string, so the destination is always a list of this admin, whatever a crafted form puts in
     * it. A full address in that field would be an open redirect.
     *
     * THE REPORT WINS OVER A PARAMETER OF THE SAME NAME. A `query` that already carried a `bulk`
     * key — from an address somebody kept — would otherwise describe an action that did not
     * happen in this request.
     *
     * @param array<string,string|int> $report
     */
    private static function back(string $list, Request $request, array $report = array()): Response
    {
        $carried = array();
        parse_str((string) $request->request->get('query', ''), $carried);

        $params = $report + array_diff_key($carried, array_flip(self::REPORT_KEYS));

        return new Response('', 303, array(
            'Location' => $params === array() ? $list : $list.'?'.http_build_query($params),
        ));
    }

    /**
     * The parameter names the report owns.
     *
     * Listed so that `back()` can strip them out of a carried query string: they belong to the
     * action that just ran, and a stale copy of them would be a report about nothing.
     *
     * @var list<string>
     */
    public const REPORT_KEYS = array('bulk', 'done', 'failed', 'ids', 'code');
}
