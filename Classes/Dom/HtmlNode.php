<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Dom;

use Masterminds\HTML5;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * A document or element of a rendered page, for PHP below 8.4.
 *
 * It offers the part of PHP 8.4's Dom\HTMLDocument / Dom\Element the tests use, with the same names, so a
 * test reads the same on both: querySelector(), querySelectorAll(), getAttribute(), hasAttribute(),
 * textContent, localName and parentElement. The HTML is parsed by an HTML5 parser, not by libxml's HTML 4
 * one.
 *
 * @property-read self|null $parentElement the element containing this one, null for the document and its root
 */
final class HtmlNode
{
    public readonly string $localName;

    public readonly string $textContent;

    private function __construct(private readonly \DOMNode $node)
    {
        $this->localName = (string)$node->localName;
        $this->textContent = $node->textContent;
    }

    public static function fromHtml(string $html): self
    {
        // without the XHTML namespace, so the XPath of a CSS selector matches
        return new self((new HTML5(['disable_html_ns' => true]))->loadHTML($html));
    }

    /**
     * parentElement is resolved on access: creating it with every node would walk up to the root each time.
     */
    public function __get(string $name): ?self
    {
        if ($name !== 'parentElement') {
            throw new \LogicException(sprintf('HtmlNode has no property "%s".', $name), 1763990201);
        }
        $parent = $this->node->parentNode;

        return $parent instanceof \DOMElement ? new self($parent) : null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'parentElement' && $this->node->parentNode instanceof \DOMElement;
    }

    public function querySelector(string $selector): ?self
    {
        return $this->querySelectorAll($selector)[0] ?? null;
    }

    /**
     * @return list<self>
     */
    public function querySelectorAll(string $selector): array
    {
        $document = $this->node instanceof \DOMDocument ? $this->node : $this->node->ownerDocument;
        if ($document === null) {
            return [];
        }
        // As in the DOM, the selector is matched against the whole document and the result is limited to
        // the descendants of this node: a query relative to this node would miss "main p" on an element
        // inside <main>, whose XPath starts with a step for an ancestor.
        $nodes = (new \DOMXPath($document))->query(
            (new CssSelectorConverter())->toXPath($selector, 'descendant-or-self::'),
            $document,
        );
        if ($nodes === false) {
            throw new \InvalidArgumentException(sprintf('Selector "%s" is not valid.', $selector), 1763560601);
        }

        $elements = [];
        foreach ($nodes as $node) {
            // a selector matches elements: no namespace nodes
            if ($node instanceof \DOMNode && $this->isAncestorOf($node)) {
                $elements[] = new self($node);
            }
        }

        return $elements;
    }

    private function isAncestorOf(\DOMNode $node): bool
    {
        for ($ancestor = $node->parentNode; $ancestor !== null; $ancestor = $ancestor->parentNode) {
            if ($ancestor->isSameNode($this->node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value of the attribute, null when the element does not have it.
     */
    public function getAttribute(string $name): ?string
    {
        return $this->hasAttribute($name) && $this->node instanceof \DOMElement ? $this->node->getAttribute($name) : null;
    }

    public function hasAttribute(string $name): bool
    {
        return $this->node instanceof \DOMElement && $this->node->hasAttribute($name);
    }
}
