<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Wizhippo\IbexaContentAssistant\Exception\InvalidRichTextSuggestion;
use Wizhippo\IbexaContentAssistant\Service\IbexaRichTextProcessor;

final class IbexaRichTextProcessorTest extends KernelTestCase
{
    private const array CAPABILITIES = ['headingLevels' => [1, 2, 3, 4, 5, 6], 'tables' => true, 'lists' => true];

    private function processor(): IbexaRichTextProcessor
    {
        return self::getContainer()->get(IbexaRichTextProcessor::class);
    }

    private static function xml(string $content): string
    {
        return '<section xmlns="http://docbook.org/ns/docbook" xmlns:ezxhtml="http://ibexa.co/xmlns/dxp/docbook/xhtml" xmlns:xlink="http://www.w3.org/1999/xlink" version="5.0-variant ezpublish-1.0">'.$content.'</section>';
    }

    public function testNativeEditorInputSchemaValidationRenderingAndRoundTrip(): void
    {
        $processor = $this->processor();
        $source = $processor->read('<section xmlns="http://ibexa.co/namespaces/ezpublish5/xhtml5/edit"><p>Original body</p></section>');
        self::assertSame(IbexaRichTextProcessor::DOCBOOK, $source->documentElement->namespaceURI);
        $output = self::xml('<title ezxhtml:level="2">Itinerary</title><informaltable border="1"><thead><tr><th><para>Day</para></th><th><para>Port</para></th></tr></thead><tbody><tr><td><para>Monday</para></td><td><para>San Juan</para></td></tr></tbody></informaltable><itemizedlist><listitem><para>Training included</para></listitem></itemizedlist>');
        $document = $processor->generated($output, [], $source, false, self::CAPABILITIES);
        $rendered = $processor->render($document);
        self::assertStringContainsString('<h2', $rendered['editXhtml']);
        self::assertStringContainsString('<table', $rendered['editXhtml']);
        self::assertStringContainsString('<h2', $rendered['previewHtml']);
        self::assertStringContainsString('<table', $rendered['previewHtml']);
        self::assertStringContainsString('<ul', $rendered['previewHtml']);
        $roundTrip = $processor->read($rendered['editXhtml']);
        self::assertSame('Itinerary', $roundTrip->getElementsByTagName('title')->item(0)->textContent);
        self::assertSame(1, $roundTrip->getElementsByTagName('informaltable')->length);
    }

    public function testNativeLineBreakMarkupIsAcceptedAndRoundTrips(): void
    {
        $processor = $this->processor();
        $source = $processor->read('<section xmlns="http://ibexa.co/namespaces/ezpublish5/xhtml5/edit"><p>Line one<br/>Line two</p></section>');
        self::assertSame('normal', $source->getElementsByTagName('literallayout')->item(0)->getAttribute('class'));
        $protected = $processor->protect($source);
        $document = $processor->generated($protected['xml'], $protected['protected'], $source, false, self::CAPABILITIES);
        $rendered = $processor->render($document);
        self::assertStringContainsString('<br', $rendered['editXhtml']);
        self::assertStringContainsString('Line two', $rendered['previewHtml']);
        self::assertStringContainsString('Line two', $processor->read($rendered['editXhtml'])->textContent);
    }

    /** @dataProvider literalLayoutClasses */
    public function testAcceptsNativeDocBookLiteralLayoutClasses(string $class): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<para>Existing body.</para>'));
        $document = $processor->generated(self::xml('<literallayout class="'.$class.'">Line one
Line two</literallayout>'), [], $source, false, self::CAPABILITIES);
        self::assertSame($class, $document->getElementsByTagName('literallayout')->item(0)->getAttribute('class'));
    }

    public static function literalLayoutClasses(): iterable
    {
        yield ['normal'];
        yield ['monospaced'];
    }

    public function testOriginalEmbedsAndCustomTagsAreProtectedAndRestoredExactly(): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<para>Before</para><ezembed xlink:href="ezcontent://106" view="embed"><ezconfig><ezvalue key="size">medium</ezvalue></ezconfig></ezembed><para>Inline <ezembedinline xlink:href="ezcontent://107" view="embed-inline"/></para><eztemplate name="worldia_sso"><ezconfig><ezvalue key="token">PRIVATE_VALUE</ezvalue></ezconfig></eztemplate>'));
        $protected = $processor->protect($source);
        self::assertCount(3, $protected['protected']);
        self::assertStringNotContainsString('PRIVATE_VALUE', $protected['xml']);
        self::assertStringNotContainsString('ezcontent://106', $protected['xml']);
        $restored = $processor->generated($protected['xml'], $protected['protected'], $source, false, self::CAPABILITIES);
        foreach (['ezembed', 'ezembedinline', 'eztemplate'] as $name) {
            self::assertSame($source->getElementsByTagName($name)->item(0)->C14N(), $restored->getElementsByTagName($name)->item(0)->C14N());
        }
    }

    public function testMissingDuplicatedOrReorderedEmbedsAreRejected(): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<ezembed xlink:href="ezcontent://106" view="embed"/>'));
        $protected = $processor->protect($source);
        $this->expectException(InvalidRichTextSuggestion::class);
        $processor->generated(self::xml('<para>The model removed the embed.</para>'), $protected['protected'], $source, false, self::CAPABILITIES);
    }

    public function testAppendKeepsOriginalContentAndEmbeds(): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<para>Existing content.</para><ezembed xlink:href="ezcontent://106" view="embed"/>'));
        $protected = $processor->protect($source);
        $document = $processor->generated(self::xml('<title ezxhtml:level="2">New section</title><para>New article.</para>'), $protected['protected'], $source, true, self::CAPABILITIES);
        self::assertSame(1, $document->getElementsByTagName('ezembed')->length);
        self::assertStringContainsString('Existing content.', $document->textContent);
        self::assertStringContainsString('New article.', $document->textContent);
    }

    public function testDuplicateEmbedPlaceholderIsRejected(): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<ezembed xlink:href="ezcontent://106" view="embed"/>'));
        $protected = $processor->protect($source);
        $token = array_key_first($protected['protected']);
        $this->expectException(InvalidRichTextSuggestion::class);
        $processor->generated(self::xml('<para>'.$token.'</para><para>'.$token.'</para>'), $protected['protected'], $source, false, self::CAPABILITIES);
    }

    public function testExtendedTableMetadataIsPreservedAsACompleteBlock(): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<informaltable border="1"><tbody><tr><td><para><ezembedinline xlink:href="ezcontent://106" view="embed-inline"/></para></td><td><ezattribute><ezvalue key="label">Private metadata</ezvalue></ezattribute><para>Value</para></td></tr></tbody></informaltable>'));
        $protected = $processor->protect($source);
        $restored = $processor->generated($protected['xml'], $protected['protected'], $source, false, self::CAPABILITIES);
        self::assertSame($source->documentElement->C14N(), $restored->documentElement->C14N());
    }

    /** @dataProvider invalidDocuments */
    public function testRejectsUnsafeOrInvalidGeneratedMarkup(string $content): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<para>Body</para>'));
        $this->expectException(InvalidRichTextSuggestion::class);
        $processor->generated($content, [], $source, false, self::CAPABILITIES);
    }

    public static function invalidDocuments(): iterable
    {
        yield 'script' => [self::xml('<script>alert(1)</script>')];
        yield 'invented heading namespace' => [str_replace('http://ibexa.co/xmlns/dxp/docbook/xhtml', 'http://ibexa.co/blackboard/xmlns/dxp/docbook/xhtml', self::xml('<title ezxhtml:level="3">Heading</title>'))];
        yield 'CSS class on paragraph' => [self::xml('<para class="normal">Body</para>')];
        yield 'arbitrary literal layout class' => [self::xml('<literallayout class="custom-css">Body</literallayout>')];
        yield 'namespaced CSS class' => [self::xml('<literallayout ezxhtml:class="normal">Body</literallayout>')];
        yield 'event attribute' => [self::xml('<para onclick="alert(1)">Body</para>')];
        yield 'wrong namespace' => ['<section xmlns="http://www.w3.org/1999/xhtml"><p>HTML</p></section>'];
        yield 'invalid table schema' => [self::xml('<informaltable><para>No rows or cells</para></informaltable>')];
        yield 'invented embed' => [self::xml('<ezembed xlink:href="ezcontent://999" view="embed"/>')];
        yield 'unsafe link' => [self::xml('<para><link xlink:href="javascript:alert(1)">Link</link></para>')];
        yield 'unknown placeholder' => [self::xml('<para>AI_KEEP_aaaaaaaaaaaaaaaaaaaaaaaa</para>')];
        yield 'doctype and entity' => ['<!DOCTYPE section [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'.self::xml('<para>&secret;</para>')];
    }

    public function testPreservesOriginalLinkDestinations(): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<para><link xlink:href="https://example.com">Supplier</link></para>'));
        $this->expectException(InvalidRichTextSuggestion::class);
        $processor->generated(self::xml('<para>Removed the link.</para>'), [], $source, false, self::CAPABILITIES);
    }

    public function testRejectsFeaturesDisabledInActualEditor(): void
    {
        $processor = $this->processor();
        $source = $processor->read(self::xml('<para>Body</para>'));
        $this->expectException(InvalidRichTextSuggestion::class);
        $processor->generated(self::xml('<title ezxhtml:level="2">Heading</title>'), [], $source, false, ['headingLevels' => [], 'tables' => false, 'lists' => false]);
    }
}
