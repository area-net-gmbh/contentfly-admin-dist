<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use DOMDocument;
use DOMElement;

/**
 * The markup a rich text field may contain — and the gate that enforces it.
 *
 * WHY THIS IS NOT OPTIONAL. A `contenteditable` hands over whatever the browser produced, a
 * paste from a word processor brings along a whole stylesheet, and the stored value is rendered
 * as HTML again the next time the record is opened. Without a filter that is a stored
 * cross-site-scripting hole aimed at the editorial team itself — the one group that opens every
 * record of the installation.
 *
 * THE FILTER IS ON THE SERVER, NOT IN THE CONTROLLER, and that distinction is the whole point.
 * The Stimulus controller is the convenience; it can be bypassed by anybody who posts the form
 * by hand, which costs one line of `curl`. A guarantee that lives in JavaScript is not a
 * guarantee. `FormController::payload()` therefore runs every rich text value through here
 * before it reaches the core, whether a controller was ever loaded or not.
 *
 * THE ALLOWLIST IS EXACTLY WHAT THE WIDGET CAN PRODUCE (bold, italic, paragraph, list, link) and
 * nothing else — a deny list would need updating every time a browser learns a new element.
 * Anything outside it is *unwrapped* rather than deleted, so a paste keeps its text and loses
 * only its markup; a handful of elements are deleted whole, because their content is not text
 * that anybody meant to write.
 *
 * `src/View/` IS THE PLACE because this class is the contract of one widget: it says what the
 * rich text fragment may put on the page and therefore what may come back from it. It is not
 * schema access (`src/Schema/`) and not authentication (`src/Auth/`).
 */
final class RichText
{
    /** The widget key a rich text field renders with (ADR 0016 §3). */
    public const WIDGET = 'rte';

    /** The only core alias `#[Admin(rte: true)]` has an effect on. */
    public const TYPE = 'textarea';

    /** Elements that survive, with the attributes each may keep. */
    private const ALLOWED = array(
        'p'      => array(),
        'br'     => array(),
        'strong' => array(),
        'em'     => array(),
        'ul'     => array(),
        'ol'     => array(),
        'li'     => array(),
        'a'      => array('href'),
    );

    /**
     * Elements that are removed together with their content.
     *
     * Everything else outside the allowlist is unwrapped instead. These are the ones where the
     * content is not prose: a script's body is code, a style's body is CSS, and the embedding
     * elements carry their payload in attributes that are about to be stripped anyway — keeping
     * their text would leave unreadable fragments in the middle of a sentence.
     */
    private const DROPPED = array('script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template');

    /**
     * What `b` and `i` become.
     *
     * Browsers still produce them for bold and italic, and they mean the same thing to a reader.
     * Renaming is kinder than unwrapping: unwrapping would silently remove the formatting an
     * editor just applied.
     *
     * `div` IS DELIBERATELY NOT IN HERE. Renaming it to `p` was the first version and it was
     * wrong: a pasted `<div><p>Text</p></div>` became `<p><p>Text</p></p>`, which no browser
     * accepts — it closes the outer paragraph early and the editor sees two empty ones. A `div`
     * is a container and nothing else, so it is unwrapped like any other element outside the
     * allowlist and its children keep their own meaning.
     */
    private const RENAMED = array('b' => 'strong', 'i' => 'em');

    /** URL schemes a link may use. Everything else loses the `href`, including `javascript:`. */
    private const SAFE_HREF = '#^(?:https?://|mailto:|tel:|/|\#)#i';

    /**
     * One submitted rich text value, reduced to what is allowed.
     *
     * An empty result comes back as the empty string rather than as empty markup, so that
     * clearing a rich text field means the same thing to the core as clearing a plain one.
     */
    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument();

        /*
         * THE WRAPPER AND THE FLAGS ARE BOTH LOAD-BEARING. `loadHTML()` on a fragment otherwise
         * invents `<html><body>` around it, and the meta charset is how UTF-8 survives — without
         * it an umlaut comes back as mojibake, because the parser assumes ISO-8859-1. The
         * internal-errors switch keeps a malformed paste from emitting PHP warnings into the
         * response body.
         */
        $previous = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="contentfly-rte-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            /*
             * UNPARSEABLE MEANS TEXT, NOT TRUST. The value still has to be stored somehow, and
             * the one answer that cannot be wrong is to keep it as plain text with its markup
             * escaped — the editor sees what they wrote and nothing of it can execute.
             */
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $root = $document->getElementById('contentfly-rte-root');

        if (!$root instanceof DOMElement) {
            return '';
        }

        self::walk($root);

        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return trim($result) === '' ? '' : $result;
    }

    /**
     * Walks one element's children, depth first, and applies the allowlist.
     *
     * THE CHILD LIST IS COPIED BEFORE ITERATING. `DOMNodeList` is live: removing or unwrapping a
     * node while walking it skips the next sibling, which would let every second disallowed
     * element through. That is the kind of filter bug that passes a casual test and fails on the
     * one paste that matters.
     */
    private static function walk(DOMElement $element): void
    {
        $children = array();

        foreach ($element->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (!$child instanceof DOMElement) {
                /* Text and nothing else: comments and processing instructions go. */
                if ($child->nodeType !== XML_TEXT_NODE) {
                    $element->removeChild($child);
                }

                continue;
            }

            $name = strtolower($child->nodeName);

            if (in_array($name, self::DROPPED, true)) {
                $element->removeChild($child);
                continue;
            }

            if (isset(self::RENAMED[$name])) {
                $child = self::rename($child, self::RENAMED[$name]);
                $name  = self::RENAMED[$name];
            }

            if (!isset(self::ALLOWED[$name])) {
                self::walk($child);
                self::unwrap($child);
                continue;
            }

            self::strip($child, self::ALLOWED[$name]);
            self::walk($child);
        }
    }

    /** Replaces one element by the same element under a different name, children and all. */
    private static function rename(DOMElement $element, string $name): DOMElement
    {
        $replacement = $element->ownerDocument->createElement($name);

        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }

        $element->parentNode->replaceChild($replacement, $element);

        return $replacement;
    }

    /** Removes one element and puts its children in its place. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * Removes every attribute the element may not keep, and every unsafe `href`.
     *
     * @param list<string> $keep
     */
    private static function strip(DOMElement $element, array $keep): void
    {
        $names = array();

        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }

        foreach ($names as $name) {
            if (!in_array(strtolower($name), $keep, true)) {
                $element->removeAttribute($name);
                continue;
            }

            if (strtolower($name) === 'href' && preg_match(self::SAFE_HREF, trim($element->getAttribute('href'))) !== 1) {
                $element->removeAttribute($name);
            }
        }

        /* A link that survives opens in the same tab; `target` was just stripped on purpose. */
    }
}
