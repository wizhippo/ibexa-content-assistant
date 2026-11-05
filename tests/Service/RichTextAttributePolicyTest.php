<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Service;

use Ibexa\Contracts\FieldTypeRichText\RichText\InputHandlerInterface;
use Ibexa\FieldTypeRichText\RichText\Converter\Xslt;
use Ibexa\FieldTypeRichText\RichText\Renderer;
use Ibexa\FieldTypeRichText\RichText\Validator\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Wizhippo\IbexaContentAssistant\Exception\InvalidRichTextSuggestion;
use Wizhippo\IbexaContentAssistant\Service\IbexaRichTextProcessor;

/** Uses the installed Ibexa schema and both native XSL converters, without a kernel. */
final class RichTextAttributePolicyTest extends TestCase
{
    private const array CAPABILITIES = ['headingLevels' => [1, 2, 3, 4, 5, 6], 'tables' => true, 'lists' => true];

    /** @dataProvider linkTitles */
    public function testLinkTitleValidatesRendersAndRoundTrips(string $title): void
    {
        $processor = $this->processor();
        $xml = self::xml('<para><link xlink:href="https://example.com/?a=1&amp;b=2" xlink:show="new" xlink:title="'.$title.'">Supplier</link></para>');
        $source = self::document($xml);
        self::assertSame([], $this->validator()->validateDocument($source));
        $document = $processor->generated($xml, [], $source, false, self::CAPABILITIES);
        self::assertSame($title, $document->getElementsByTagName('link')->item(0)->getAttributeNS(IbexaRichTextProcessor::XLINK, 'title'));
        $rendered = $processor->render($document);
        self::assertStringContainsString('title="'.$title.'"', $rendered['editXhtml']);
        $roundTrip = $processor->read($rendered['editXhtml']);
        self::assertSame($title, $roundTrip->getElementsByTagName('link')->item(0)->getAttributeNS(IbexaRichTextProcessor::XLINK, 'title'));
        self::assertSame('https://example.com/?a=1&b=2', $roundTrip->getElementsByTagName('link')->item(0)->getAttributeNS(IbexaRichTextProcessor::XLINK, 'href'));
        self::assertStringContainsString('Supplier', $rendered['previewHtml']);
    }

    public static function linkTitles(): iterable
    {
        yield 'empty title' => [''];
        yield 'nonempty title' => ['Supplier details'];
    }

    public function testNativeFormattingAttributesValidateAndRender(): void
    {
        $processor = $this->processor();
        $xml = self::xml('<title xml:id="heading" ezxhtml:level="2" ezxhtml:textalign="center">Heading</title><para xml:id="body" ezxhtml:textalign="right"><emphasis role="underlined">Underlined</emphasis> <emphasis role="strikedthrough">Removed</emphasis><subscript>2</subscript><superscript>3</superscript><literallayout class="normal">First&#10;Second</literallayout></para><orderedlist numeration="upperroman" startingnumber="3"><listitem><para>Item</para></listitem></orderedlist><itemizedlist><listitem><para>Bullet</para></listitem></itemizedlist><blockquote><para>Quote</para></blockquote><informaltable width="80%" border="1" title="Schedule"><thead><tr ezxhtml:width="100%"><th scope="col" abbr="Day" valign="middle" ezxhtml:width="50%" ezxhtml:textalign="center"><para>Day</para></th></tr></thead><tbody><tr><td colspan="1" rowspan="1" valign="top" ezxhtml:width="50%" ezxhtml:textalign="right"><para>Monday</para></td></tr></tbody></informaltable>');
        self::assertSame([], $this->validator()->validateDocument(self::document($xml)));
        $document = $processor->generated($xml, [], self::document(self::xml('<para>Body</para>')), false, self::CAPABILITIES);
        $rendered = $processor->render($document);
        foreach (['id="heading"', 'text-align:center', 'text-align:right', 'vertical-align:middle', 'vertical-align:top', 'abbr="Day"', 'scope="col"', 'width:50%', 'title="Schedule"', '<ol', '<ul', '<blockquote', '<br'] as $expected) {
            self::assertStringContainsString($expected, $rendered['editXhtml']);
        }
        $roundTrip = $processor->read($rendered['editXhtml']);
        self::assertSame('center', $roundTrip->getElementsByTagName('title')->item(0)->getAttributeNS(IbexaRichTextProcessor::XHTML, 'textalign'));
        self::assertSame('middle', $roundTrip->getElementsByTagName('th')->item(0)->getAttribute('valign'));
        self::assertSame('Day', $roundTrip->getElementsByTagName('th')->item(0)->getAttribute('abbr'));
        self::assertSame('Schedule', $roundTrip->getElementsByTagName('informaltable')->item(0)->getAttribute('title'));
    }

    public function testNamespaceIdentityUsesUrisRatherThanPrefixes(): void
    {
        $xml = str_replace(['xmlns:xlink=', 'xlink:title'], ['xmlns:alias="'.IbexaRichTextProcessor::XLINK.'" xmlns:xlink=', 'alias:title'], self::xml('<para><link xlink:href="https://example.com" xlink:title="">Link</link></para>'));
        $document = $this->processor()->generated($xml, [], self::document($xml), false, self::CAPABILITIES);
        self::assertTrue($document->getElementsByTagName('link')->item(0)->hasAttributeNS(IbexaRichTextProcessor::XLINK, 'title'));
    }

    /** @dataProvider unsupportedAttributes */
    public function testRejectsWrongAttributeContextsBeforeSchemaValidation(string $content): void
    {
        try {
            $this->processor()->generated(self::xml($content), [], self::document(self::xml('<para>Body</para>')), false, self::CAPABILITIES);
            self::fail('Expected an attribute policy rejection.');
        } catch (InvalidRichTextSuggestion $exception) {
            self::assertStringContainsString('Unsupported generated attribute', $exception->getPrevious()->getMessage());
        }
    }

    public static function unsupportedAttributes(): iterable
    {
        yield 'unqualified href' => ['<para><link href="https://example.com">Link</link></para>'];
        yield 'unqualified link title' => ['<para><link xlink:href="https://example.com" title="Title">Link</link></para>'];
        yield 'xhtml link title' => ['<para><link xlink:href="https://example.com" ezxhtml:title="Title">Link</link></para>'];
        yield 'foreign namespace' => ['<para xmlns:foreign="urn:foreign" foreign:id="anchor">Body</para>'];
        yield 'DocBook namespace on attribute' => ['<para xmlns:db="http://docbook.org/ns/docbook" db:id="anchor">Body</para>'];
        yield 'unqualified id' => ['<para id="anchor">Body</para>'];
        yield 'unqualified level' => ['<title level="2">Heading</title>'];
        yield 'xlink level' => ['<title xlink:level="2">Heading</title>'];
        yield 'level on paragraph' => ['<para ezxhtml:level="2">Body</para>'];
        yield 'unqualified textalign' => ['<para textalign="center">Body</para>'];
        yield 'title on paragraph' => ['<para xlink:title="Title">Body</para>'];
        yield 'link on paragraph bypasses URL checks' => ['<para xlink:href="javascript:evil">Body</para>'];
        yield 'role on paragraph' => ['<para role="strong">Body</para>'];
        yield 'width on paragraph' => ['<para ezxhtml:width="50%">Body</para>'];
        yield 'table width wrong namespace' => ['<informaltable ezxhtml:width="50%"><tbody><tr><td><para>Cell</para></td></tr></tbody></informaltable>'];
        yield 'cell width wrong namespace' => [self::table('<td width="50%"><para>Cell</para></td>')];
        yield 'cell valign wrong namespace' => [self::table('<td ezxhtml:valign="top"><para>Cell</para></td>')];
        yield 'event handler allowed by schema' => [self::table('<td onclick="evil"><para>Cell</para></td>')];
        yield 'inline style allowed by schema' => [self::table('<td style="background:url(javascript:evil)"><para>Cell</para></td>')];
        yield 'table class allowed by schema' => [self::table('<td class="arbitrary"><para>Cell</para></td>')];
        yield 'custom style' => ['<para ezxhtml:class="custom">Body</para>'];
        yield 'base URI' => ['<para xml:base="https://example.com">Body</para>'];
        yield 'XLink automatic traversal' => ['<para><link xlink:href="https://example.com" xlink:actuate="onLoad">Link</link></para>'];
        yield 'routing metadata' => ['<para><link xlink:href="https://example.com" xlink:siteaccess="admin">Link</link></para>'];
    }

    /** @dataProvider invalidValues */
    public function testSchemaStillChecksAllowedAttributeValues(string $content): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('RichText generation rejected: schema validation failed.', self::callback(static fn (array $context): bool => $context['stage'] === 'generated' && $context['error_count'] > 0 && count($context) === 2));
        try {
            $this->processor($logger)->generated(self::xml($content), [], self::document(self::xml('<para>Body</para>')), false, self::CAPABILITIES);
            self::fail('Expected schema rejection.');
        } catch (InvalidRichTextSuggestion $exception) {
            self::assertStringContainsString('Ibexa RichText schema', $exception->getPrevious()->getMessage());
        }
    }

    public static function invalidValues(): iterable
    {
        yield 'text alignment injection' => ['<para ezxhtml:textalign="left;PRIVATE_SECRET">Body</para>'];
        yield 'width injection' => [self::table('<td ezxhtml:width="50%;PRIVATE_SECRET"><para>Cell</para></td>')];
        yield 'valign injection' => [self::table('<td valign="top;PRIVATE_SECRET"><para>Cell</para></td>')];
        yield 'colspan' => [self::table('<td colspan="PRIVATE_SECRET"><para>Cell</para></td>')];
        yield 'list enumeration' => ['<orderedlist numeration="PRIVATE_SECRET"><listitem><para>Item</para></listitem></orderedlist>'];
        yield 'invalid structure' => ['<informaltable><para>PRIVATE_SECRET</para></informaltable>'];
    }

    public function testDiagnosticsNeverIncludeUnknownVocabularyOrValues(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('RichText generation rejected: unsupported attribute.', ['element' => 'para', 'attribute' => '[unrecognized]', 'namespace' => '[unrecognized]', 'line' => 1]);
        try {
            $this->processor($logger)->generated(self::xml('<para xmlns:secret="https://PRIVATE_SECRET" secret:PRIVATE_SECRET="https://user:password@example.com">PRIVATE_PROSE</para>'), [], self::document(self::xml('<para>Body</para>')), false, self::CAPABILITIES);
            self::fail('Expected policy rejection.');
        } catch (InvalidRichTextSuggestion $exception) {
            self::assertStringNotContainsString('PRIVATE', $exception->getPrevious()->getMessage());
            self::assertStringNotContainsString('password', $exception->getPrevious()->getMessage());
        }
    }

    /** @dataProvider restrictedValues */
    public function testPolicyValueRestrictionsRemainEnforced(string $content): void
    {
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->processor()->generated(self::xml($content), [], self::document(self::xml('<para>Body</para>')), false, self::CAPABILITIES);
    }

    public static function restrictedValues(): iterable
    {
        yield ['<literallayout class="custom">Body</literallayout>'];
        yield ['<para><emphasis role="arbitrary-style">Body</emphasis></para>'];
        yield ['<para><link xlink:href="https://example.com" xlink:show="embed">Link</link></para>'];
        yield ['<para><link xlink:href="javascript:evil">Link</link></para>'];
        yield ['<para><link xlink:href="ezcontent://999">Link</link></para>'];
        yield ['<script>evil</script>'];
        yield ['<ezembed xlink:href="ezcontent://999"/>'];
        yield ['<eztemplate name="unconfigured"><ezcontent/></eztemplate>'];
    }

    /** @dataProvider disabledCapabilities */
    public function testEditorCapabilitiesStillRestrictGeneratedStructures(string $content, array $capabilities): void
    {
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->processor()->generated(self::xml($content), [], self::document(self::xml('<para>Body</para>')), false, array_replace(self::CAPABILITIES, $capabilities));
    }

    public static function disabledCapabilities(): iterable
    {
        yield 'heading' => ['<title ezxhtml:level="2">Heading</title>', ['headingLevels' => [1]]];
        yield 'table' => [self::table('<td><para>Cell</para></td>'), ['tables' => false]];
        yield 'unordered list' => ['<itemizedlist><listitem><para>Item</para></listitem></itemizedlist>', ['lists' => false]];
        yield 'ordered list' => ['<orderedlist><listitem><para>Item</para></listitem></orderedlist>', ['lists' => false]];
    }

    public function testContractExamplesPassActualPolicyAndSchema(): void
    {
        $processor = $this->processor();
        $contract = $processor->contract(self::CAPABILITIES);
        self::assertSame(['href', 'show', 'title'], $contract['attributes']['link'][IbexaRichTextProcessor::XLINK]);
        self::assertSame(['id'], $contract['attributes']['para'][IbexaRichTextProcessor::XML]);
        foreach ($contract['examples'] as $example) {
            $xml = self::xml($example);
            self::assertInstanceOf(\DOMDocument::class, $processor->generated($xml, [], self::document($xml), false, self::CAPABILITIES));
        }
    }

    private static function table(string $cell): string
    {
        return '<informaltable><tbody><tr>'.$cell.'</tr></tbody></informaltable>';
    }

    private static function xml(string $body): string
    {
        return '<section xmlns="'.IbexaRichTextProcessor::DOCBOOK.'" xmlns:ezxhtml="'.IbexaRichTextProcessor::XHTML.'" xmlns:xlink="'.IbexaRichTextProcessor::XLINK.'" version="5.0-variant ezpublish-1.0">'.$body.'</section>';
    }

    private static function document(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    private function resources(): string
    {
        return dirname((new \ReflectionClass(Renderer::class))->getFileName(), 3).'/bundle/Resources/richtext';
    }

    private function validator(): Validator
    {
        $resources = $this->resources();

        return new Validator([$resources.'/schemas/docbook/ezpublish.rng', $resources.'/schemas/docbook/docbook.iso.sch.xsl']);
    }

    private function processor(?LoggerInterface $logger = null): IbexaRichTextProcessor
    {
        $resources = $this->resources();
        $inputConverter = new Xslt($resources.'/stylesheets/xhtml5/edit/docbook.xsl');
        $input = $this->createMock(InputHandlerInterface::class);
        $input->method('fromString')->willReturnCallback(static fn (string $xml): \DOMDocument => $inputConverter->convert(self::document($xml)));
        $edit = new Xslt($resources.'/stylesheets/docbook/xhtml5/edit/xhtml5.xsl', [['priority' => 0, 'path' => $resources.'/stylesheets/docbook/xhtml5/edit/core.xsl']]);
        $output = new Xslt($resources.'/stylesheets/docbook/xhtml5/output/xhtml5.xsl', [['priority' => 0, 'path' => $resources.'/stylesheets/docbook/xhtml5/output/core.xsl']]);

        return new IbexaRichTextProcessor($input, $this->validator(), $edit, $output, logger: $logger);
    }
}
