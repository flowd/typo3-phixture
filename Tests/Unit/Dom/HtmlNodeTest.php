<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Tests\Unit\Dom;

use Flowd\Typo3Phixture\Dom\HtmlNode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\CssSelector\Exception\SyntaxErrorException;

final class HtmlNodeTest extends TestCase
{
    private const string HTML = <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head><title>Title of the page</title></head>
        <body>
            <main class="content" data-empty="">
                <article id="first">
                    <h2>First</h2>
                    <p class="intro">One</p>
                    <p>Two</p>
                    <svg class="icon"><use xlink:href="#sprite"></use></svg>
                </article>
                <article id="second">
                    <h2>Second</h2>
                    <p>Three</p>
                </article>
            </main>
        </body>
        </html>
        HTML;

    #[Test]
    public function documentHasTheTextOfThePage(): void
    {
        $document = HtmlNode::fromHtml(self::HTML);

        self::assertStringContainsString('Title of the page', $document->textContent);
        self::assertStringContainsString('Three', $document->textContent);
    }

    #[Test]
    public function querySelectorReturnsTheFirstMatch(): void
    {
        $element = HtmlNode::fromHtml(self::HTML)->querySelector('article p');

        self::assertNotNull($element);
        self::assertSame('p', $element->localName);
        self::assertSame('One', $element->textContent);
    }

    #[Test]
    public function querySelectorReturnsNullWithoutMatch(): void
    {
        self::assertNull(HtmlNode::fromHtml(self::HTML)->querySelector('table'));
    }

    #[Test]
    public function querySelectorAllReturnsAllMatchesInDocumentOrder(): void
    {
        $paragraphs = HtmlNode::fromHtml(self::HTML)->querySelectorAll('p');

        self::assertSame(['One', 'Two', 'Three'], array_map(static fn(HtmlNode $node): string => $node->textContent, $paragraphs));
    }

    #[Test]
    public function querySelectorAllReturnsAnEmptyListWithoutMatch(): void
    {
        self::assertSame([], HtmlNode::fromHtml(self::HTML)->querySelectorAll('table'));
    }

    #[Test]
    public function queryOnAnElementIsLimitedToItsDescendants(): void
    {
        $second = HtmlNode::fromHtml(self::HTML)->querySelector('#second');

        self::assertNotNull($second);
        self::assertCount(1, $second->querySelectorAll('p'));
        self::assertSame('Three', $second->querySelector('p')?->textContent);
    }

    #[Test]
    public function queryOnAnElementDoesNotReturnTheElementItself(): void
    {
        $article = HtmlNode::fromHtml(self::HTML)->querySelector('article');

        self::assertNotNull($article);
        self::assertSame([], $article->querySelectorAll('article'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function selectorsMatchedAgainstTheWholeDocument(): iterable
    {
        yield 'position among the children of the element' => ['p:nth-child(2)', 'One'];
        yield 'child combinator starting at the element' => ['article > p.intro', 'One'];
        yield 'ancestor outside the element' => ['main.content p.intro', 'One'];
        yield 'last child' => ['p:nth-child(3)', 'Two'];
    }

    #[Test]
    #[DataProvider('selectorsMatchedAgainstTheWholeDocument')]
    public function queryOnAnElementMatchesTheSelectorAgainstTheWholeDocument(string $selector, string $expectedText): void
    {
        $first = HtmlNode::fromHtml(self::HTML)->querySelector('#first');

        self::assertNotNull($first);
        self::assertSame($expectedText, $first->querySelector($selector)?->textContent);
    }

    #[Test]
    public function elementsOfSvgAreFoundWithoutNamespace(): void
    {
        $use = HtmlNode::fromHtml(self::HTML)->querySelector('svg.icon use');

        self::assertNotNull($use);
        self::assertSame('#sprite', $use->getAttribute('xlink:href'));
    }

    #[Test]
    public function invalidSelectorIsRejected(): void
    {
        $this->expectException(SyntaxErrorException::class);

        HtmlNode::fromHtml(self::HTML)->querySelectorAll('p[');
    }

    #[Test]
    public function getAttributeReturnsTheValue(): void
    {
        $main = HtmlNode::fromHtml(self::HTML)->querySelector('main');

        self::assertNotNull($main);
        self::assertSame('content', $main->getAttribute('class'));
        self::assertTrue($main->hasAttribute('class'));
    }

    #[Test]
    public function emptyAttributeIsDistinctFromAMissingOne(): void
    {
        $main = HtmlNode::fromHtml(self::HTML)->querySelector('main');

        self::assertNotNull($main);
        self::assertSame('', $main->getAttribute('data-empty'));
        self::assertTrue($main->hasAttribute('data-empty'));
        self::assertNull($main->getAttribute('data-missing'));
        self::assertFalse($main->hasAttribute('data-missing'));
    }

    #[Test]
    public function documentHasNoAttributes(): void
    {
        $document = HtmlNode::fromHtml(self::HTML);

        self::assertFalse($document->hasAttribute('lang'));
        self::assertNull($document->getAttribute('lang'));
    }

    #[Test]
    public function parentElementIsTheContainingElement(): void
    {
        $paragraph = HtmlNode::fromHtml(self::HTML)->querySelector('p.intro');

        self::assertNotNull($paragraph);
        self::assertTrue(isset($paragraph->parentElement));
        $parent = $paragraph->parentElement;
        self::assertSame('article', $parent->localName);
        self::assertSame('first', $parent->getAttribute('id'));
    }

    #[Test]
    public function rootElementAndDocumentHaveNoParentElement(): void
    {
        $document = HtmlNode::fromHtml(self::HTML);
        $root = $document->querySelector('html');

        self::assertNotNull($root);
        self::assertNull($root->parentElement);
        self::assertFalse(isset($root->parentElement));
        self::assertNull($document->parentElement);
        self::assertFalse(isset($document->parentElement));
    }

    #[Test]
    public function unknownPropertyIsRejected(): void
    {
        $document = HtmlNode::fromHtml(self::HTML);

        $this->expectException(\LogicException::class);
        $this->expectExceptionCode(1763990201);

        $document->__get('innerHTML');
    }

    #[Test]
    public function unknownPropertyIsNotSet(): void
    {
        self::assertFalse(HtmlNode::fromHtml(self::HTML)->__isset('innerHTML'));
    }

    #[Test]
    public function html5ElementsAreParsed(): void
    {
        $document = HtmlNode::fromHtml('<main><details><summary>More</summary><p>Text</p></details></main>');

        self::assertSame('More', $document->querySelector('details > summary')?->textContent);
    }
}
