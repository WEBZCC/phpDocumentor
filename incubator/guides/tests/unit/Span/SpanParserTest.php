<?php

declare(strict_types=1);

namespace phpDocumentor\Guides\Span;

use phpDocumentor\Faker\Faker;
use phpDocumentor\Guides\ParserContext;
use phpDocumentor\Guides\RestructuredText\Span\SpanParser;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

use function current;

final class SpanParserTest extends TestCase
{
    use Faker;
    use ProphecyTrait;

    /** @var ParserContext&ObjectProphecy */
    private $parserContext;

    /** @var SpanParser */
    private $spanProcessor;

    public function setUp(): void
    {
        $this->parserContext = $this->prophesize(ParserContext::class);
        $this->parserContext->resetAnonymousStack()->hasReturnVoid();
        $this->spanProcessor = new SpanParser();
    }

    public function testInlineLiteralsAreReplacedWithToken(): void
    {
        $result = $this->spanProcessor->process(
            $this->parserContext->reveal(),
            'This text is an example of ``inline literals``.'
        );
        $token = current($this->spanProcessor->getTokens());

        self::assertStringNotContainsString('``inline literals``', $result);
        self::assertInstanceOf(LiteralToken::class, $token);
        self::assertEquals(SpanToken::TYPE_LITERAL, $token->getType());
        self::assertEquals(
            ['type' => 'literal'],
            $token->getTokenData()
        );
    }

    /** @dataProvider invalidNotationsProvider */
    public function testIncompleteStructuresAreIgnored(string $input): void
    {
        $result = $this->spanProcessor->process($this->parserContext->reveal(), $input);

        self::assertSame($input, $result);
        self::assertCount(0, $this->spanProcessor->getTokens());
    }

    public function invalidNotationsProvider(): array
    {
        return [
            'Literal start without end' => ['This text is an example of `` mis-used.'],
            'Backtick without end' => ['This text is an example of `  ` mis-used.'],
            'Interpreted text without end' => ['This text is an example of :role:`foo mis-used.'],
            'Just a colon in a text' => ['This text is an example of role: mis-used.'],
            'Embedded url start outside context' => ['This text is an example of <a>'],
        ];
    }

    /**
     * The result of this method is rather odd. There seems to be something wrong with the inline link replacement.
     * I don't think we should support this, but the regex is not covered by tests right now.
     * So improving it will be hard.
     */
    public function testIncompleteStructureLikeUrlIsReplaced(): void
    {
        $result = $this->spanProcessor->process(
            $this->parserContext->reveal(),
            'This text is an example of role:`mis-used`.'
        );
        self::assertMatchesRegularExpression('#This text is an example of [a-z0-9]{40}\\.#', $result);
    }

    /** @dataProvider namedHyperlinkReferenceProvider */
    public function testNamedHyperlinkReferencesAreReplaced(
        string $input,
        string $referenceId,
        string $text,
        string $url = ''
    ): void {
        $result = $this->spanProcessor->process($this->parserContext->reveal(), $input);
        $token = current($this->spanProcessor->getTokens());

        self::assertInstanceOf(SpanToken::class, $token);
        self::assertEquals(SpanToken::TYPE_LINK, $token->getType());
        self::assertEquals(
            [
                'type' => SpanToken::TYPE_LINK,
                'url' => $url,
                'link' => $text,
            ],
            $token->getTokenData()
        );
        self::assertRegExp($referenceId, $result);

        if ($url === '') {
            return;
        }

        $this->parserContext->setLink($text, $url)->shouldHaveBeenCalledOnce();
    }

    /** string[][[] */
    public function namedHyperlinkReferenceProvider(): array
    {
        return [
            [
                'This text is an example of link_.',
                '#This text is an example of [a-z0-9]{40}\\.#',
                'link',
            ],
            [
                'This text is an example of `Phrase Reference`_.',
                '#This text is an example of [a-z0-9]{40}\\.#',
                'Phrase Reference',
            ],
            [
                'This text is an example of `Phrase < Reference`_',
                '#This text is an example of [a-z0-9]{40}#',
                'Phrase < Reference',
            ],
            [
                <<<TEXT
This text is an example of `Phrase
                 Reference`_.
TEXT
,
                '#This text is an example of [a-z0-9]{40}#',
                'Phrase Reference',
            ],
            [
                'This is an example of `embedded urls <http://google.com>`_ in a text',
                '#This is an example of [a-z0-9]{40} in a text#',
                'embedded urls',
                'http://google.com',
            ],
            [
                'This is an example of `embedded urls alias <alias_>`_ in a text',
                '#This is an example of [a-z0-9]{40} in a text#',
                'embedded urls alias',
                'alias_',
            ],
            [
                'A more complex example `\__call() <https://www.php.net/language.oop5.overloading#object.call>`_.',
                '#A more complex example [a-z0-9]{40}\\.#',
                '__call()',
                'https://www.php.net/language.oop5.overloading#object.call',
            ],
        ];
    }

    /** @dataProvider AnonymousHyperlinksProvider */
    public function testAnonymousHyperlinksAreReplacedWithToken(
        string $input,
        string $referenceId,
        string $text,
        string $url = ''
    ): void {
        $this->testNamedHyperlinkReferencesAreReplaced($input, $referenceId, $text, $url);
        $this->parserContext->pushAnonymous($text)->shouldHaveBeenCalled()->hasReturnVoid();
    }

    public function AnonymousHyperlinksProvider(): array
    {
        return [
            [
                'This is an example of an link__',
                '#This is an example of an [a-z0-9]{40}#',
                'link',
            ],
        ];
    }

    public function testInlineInternalTargetsAreReplaced(): void
    {
        $result = $this->spanProcessor->process($this->parserContext->reveal(), 'Some _`internal ref` in text.');
        $token = current($this->spanProcessor->getTokens());

        self::assertStringNotContainsString('_`internal ref`', $result);
        self::assertInstanceOf(SpanToken::class, $token);
        self::assertEquals(SpanToken::TYPE_LINK, $token->getType());
        self::assertEquals(
            [
                'type' => SpanToken::TYPE_LINK,
                'url' => '',
                'link' => 'internal ref',
            ],
            $token->getTokenData()
        );
    }

    public function testFootNoteReferencesAreReplaced(): void
    {
        $this->markTestSkipped('Footnotes are not supported yet');
        $result = $this->spanProcessor->process($this->parserContext->reveal(), 'Please RTFM [1]_.');
        $token = current($this->spanProcessor->getTokens());

        self::assertStringNotContainsString('[1]_', $result);
        self::assertInstanceOf(SpanToken::class, $token);
        self::assertEquals(SpanToken::TYPE_REFERENCE, $token->getType());
        self::assertEquals(
            [
                'type' => SpanToken::TYPE_REFERENCE,
                'url' => '_`internal ref`',
                'link' => 'internal ref',
            ],
            $token->getTokenData()
        );
    }

    public function testEmailAddressesAreReplacedWithToken(): void
    {
        $email = $this->faker()->email;

        $result = $this->spanProcessor->process($this->parserContext->reveal(), $email);
        $tokens = $this->spanProcessor->getTokens();
        $token = current($tokens);

        self::assertStringNotContainsString($email, $result);
        self::assertCount(1, $tokens);
        self::assertSame(SpanToken::TYPE_LINK, $token->getType());
        self::assertSame(
            [
                'link' => $email,
                'url' => 'mailto:' . $email,
                'type' => SpanToken::TYPE_LINK,
            ],
            $token->getTokenData()
        );
    }

    public function testInlineUrlsAreReplacedWithToken(): void
    {
        $url = $this->faker()->url;

        $result = $this->spanProcessor->process($this->parserContext->reveal(), $url);
        $tokens = $this->spanProcessor->getTokens();
        $token = current($tokens);

        self::assertStringNotContainsString($url, $result);
        self::assertCount(1, $tokens);
        self::assertSame(SpanToken::TYPE_LINK, $token->getType());
        self::assertSame(
            [
                'link' => $url,
                'url' => $url,
                'type' => SpanToken::TYPE_LINK,
            ],
            $token->getTokenData()
        );
    }

    /**
     * @dataProvider crossReferenceProvider
     */
    public function testInterpretedTextIsParsedIntoCrossReferenceNode(
        string $span,
        string $replaced,
        string $url,
        string $role = 'ref',
        ?string $domain = null,
        ?string $anchor = null,
        ?string $text = null
    ): void {
        $result = $this->spanProcessor->process($this->parserContext->reveal(), $span);
        $token = current($this->spanProcessor->getTokens());

        self::assertStringNotContainsString($replaced, $result);
        self::assertInstanceOf(CrossReferenceNode::class, $token);
        self::assertEquals($url, $token->getUrl());
        self::assertEquals($role, $token->getRole());
        self::assertEquals($domain, $token->getDomain());
        self::assertEquals($anchor, $token->getAnchor());
        self::assertEquals($text ?? $url, $token->getText());
    }

    public function crossReferenceProvider(): array
    {
        return [
            'interpreted text without role' => [
                'span' => 'Some `title ref` in text.',
                'replaced' => '`title ref`',
                'url' => 'title ref',
            ],
            'interpreted text with role' => [
                'span' => 'Some :doc:`title ref` in text.',
                'replaced' => ':doc:`title ref`',
                'url' => 'title ref',
                'role' => 'doc',
            ],
            'interpreted text with role and anchor' => [
                'span' => 'Some :doc:`foo/subdoc#anchor` in text.',
                'replaced' => ':doc:`foo/subdoc#anchor`',
                'url' => 'foo/subdoc',
                'role' => 'doc',
                'domain' => null,
                'anchor' => 'anchor',
            ],
            'interpreted text with role, anchor and custom text' => [
                'span' => 'Some :doc:`link <foo/subdoc#anchor>` in text.',
                'replaced' => ':doc:`link <foo/subdoc#anchor>`',
                'url' => 'foo/subdoc',
                'role' => 'doc',
                'domain' => null,
                'anchor' => 'anchor',
                'text' => 'link',
            ],
            'interpreted text with domain and role' => [
                'span' => 'Some :php:class:`title ref` in text.',
                'replaced' => ':php:class:`title ref`',
                'url' => 'title ref',
                'role' => 'class',
                'domain' => 'php',
            ],
            'just a interpreted text with domain and role' => [
                'span' => ':php:class:`title ref`',
                'replaced' => ':php:class:`title ref`',
                'url' => 'title ref',
                'role' => 'class',
                'domain' => 'php',
            ],
            'php method reference' => [
                'span' => ':php:method:`phpDocumentor\Descriptor\ClassDescriptor::getParent()`',
                'replaced' => ':php:method:`phpDocumentor\Descriptor\ClassDescriptor::getParent()`',
                'url' => 'phpDocumentor\Descriptor\ClassDescriptor::getParent()',
                'role' => 'method',
                'domain' => 'php',
            ],
        ];
    }

    public function testNoReplacementsAreDoneWhenNotNeeded(): void
    {
        $result = $this->spanProcessor->process($this->parserContext->reveal(), 'Raw token');
        self::assertSame('Raw token', $result);
        self::assertEmpty($this->spanProcessor->getTokens());
    }
}
