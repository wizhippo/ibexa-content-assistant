<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Service;

use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\SiteAccess\ConfigResolverInterface;
use Ibexa\Contracts\FieldTypeRichText\Configuration\Provider;
use Ibexa\Contracts\FieldTypeRichText\RichText\InputHandlerInterface;
use Ibexa\FieldTypeRichText\RichText\Converter\Aggregate;
use Ibexa\FieldTypeRichText\RichText\Converter\Render\Template;
use Ibexa\FieldTypeRichText\RichText\Converter\Xslt;
use Ibexa\FieldTypeRichText\RichText\Renderer;
use Ibexa\FieldTypeRichText\RichText\Validator\Validator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Wizhippo\IbexaContentAssistant\Exception\InvalidRichTextSuggestion;
use Wizhippo\IbexaContentAssistant\Service\IbexaRichTextProcessor;
use Wizhippo\IbexaContentAssistant\Service\RichTextCustomTagRegistry;

final class RichTextCustomTagTest extends TestCase
{
    private const array CAPABILITIES = ['headingLevels' => [1, 2], 'tables' => true, 'lists' => true, 'customTags' => ['factbox', 'badge']];
    private const array TAGS = [
        'factbox' => ['label' => 'Fact box', 'description' => 'Highlight useful facts or warnings.', 'isInline' => false, 'attributes' => [
            'title' => ['type' => 'string', 'required' => true, 'label' => 'Title'],
            'importance' => ['type' => 'choice', 'defaultValue' => 'info', 'choices' => ['info', 'warning', 'critical']],
        ]],
        'badge' => ['isInline' => true, 'attributes' => ['label' => ['type' => 'string', 'required' => true]]],
        'disabled_tag' => ['isInline' => false],
    ];

    public function testContractUsesEnabledEditorDefinitionsWithoutTemplateDetails(): void
    {
        $contract = $this->processor()->contract(self::CAPABILITIES);
        self::assertSame(['factbox', 'badge'], array_keys($contract['custom_tags']));
        self::assertSame('eztemplate', $contract['custom_tags']['factbox']['element']);
        self::assertSame('eztemplateinline', $contract['custom_tags']['badge']['element']);
        self::assertSame(['info', 'warning', 'critical'], $contract['custom_tags']['factbox']['attributes']['importance']['choices']);
        self::assertSame('info', $contract['custom_tags']['factbox']['attributes']['importance']['default']);
        self::assertTrue($contract['custom_tags']['factbox']['attributes']['title']['required']);
        self::assertStringContainsString('Highlight useful facts', $contract['custom_tags']['factbox']['description']);
        self::assertStringNotContainsString('private-template.twig', json_encode($contract));
        self::assertSame([], $this->processor()->contract(array_replace(self::CAPABILITIES, ['customTags' => ['invented']]))['custom_tags']);
    }

    public function testFactboxDefaultsNativeValidationRenderingAndEditorRoundTrip(): void
    {
        $processor = $this->processor();
        $source = self::document('<para>Body</para>');
        $document = $processor->generated(self::xml('<eztemplate name="factbox"><ezcontent><para>Check visa requirements.</para></ezcontent><ezconfig><ezvalue key="title">Travel advice</ezvalue></ezconfig></eztemplate>'), [], $source, false, self::CAPABILITIES);
        $rendered = $processor->render($document);
        self::assertStringContainsString('data-ezelement="eztemplate"', $rendered['editXhtml']);
        self::assertStringContainsString('data-ezname="factbox"', $rendered['editXhtml']);
        self::assertStringContainsString('<aside class="info">', $rendered['previewHtml']);
        self::assertStringContainsString('Travel advice', $rendered['previewHtml']);
        self::assertStringContainsString('Check visa requirements.', $rendered['previewHtml']);
        $roundTrip = $processor->read($rendered['editXhtml']);
        self::assertSame('factbox', $roundTrip->getElementsByTagName('eztemplate')->item(0)->getAttribute('name'));
        self::assertSame('Check visa requirements.', $roundTrip->getElementsByTagName('para')->item(0)->textContent);
        $values = [];
        foreach ($roundTrip->getElementsByTagName('ezvalue') as $value) {
            $values[$value->getAttribute('key')] = $value->textContent;
        }
        self::assertSame(['title' => 'Travel advice', 'importance' => 'info'], $values);
    }

    public function testInlineTagCanBeAuthoredAndRendered(): void
    {
        $processor = $this->processor();
        $source = self::document('<para>Existing body</para>');
        $document = $processor->generated(self::xml('<para>Status <eztemplateinline name="badge"><ezcontent>New</ezcontent><ezconfig><ezvalue key="label">New item</ezvalue></ezconfig></eztemplateinline></para>'), [], $source, true, self::CAPABILITIES);
        $rendered = $processor->render($document);
        self::assertStringContainsString('Existing body', $rendered['previewHtml']);
        self::assertStringContainsString('<mark', $rendered['previewHtml']);
        self::assertStringContainsString('data-ezelement="eztemplateinline"', $rendered['editXhtml']);
        self::assertSame(1, $processor->read($rendered['editXhtml'])->getElementsByTagName('eztemplateinline')->length);
    }

    public function testExistingTagsRemainProtectedIncludingDisabledAndPrivateAttributes(): void
    {
        $processor = $this->processor();
        $source = self::document('<eztemplate name="disabled_tag"><ezconfig><ezvalue key="secret">PRIVATE</ezvalue></ezconfig></eztemplate>');
        $protected = $processor->protect($source);
        self::assertStringNotContainsString('PRIVATE', $protected['xml']);
        $restored = $processor->generated($protected['xml'], $protected['protected'], $source, false, self::CAPABILITIES);
        self::assertSame($source->documentElement->C14N(), $restored->documentElement->C14N());
    }

    /** @dataProvider invalidTags */
    public function testInvalidCustomTagsNeverBecomeSuggestions(string $tag): void
    {
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->processor()->generated(self::xml($tag), [], self::document('<para>Body</para>'), false, self::CAPABILITIES);
    }

    public static function invalidTags(): iterable
    {
        $tag = '<eztemplate name="factbox"><ezcontent><para>Fact</para></ezcontent><ezconfig><ezvalue key="title">Title</ezvalue><ezvalue key="importance">warning</ezvalue></ezconfig></eztemplate>';
        yield 'unknown name' => [str_replace('name="factbox"', 'name="invented"', $tag)];
        yield 'disabled name' => [str_replace('name="factbox"', 'name="disabled_tag"', $tag)];
        yield 'invalid choice' => [str_replace('>warning<', '>urgent<', $tag)];
        yield 'missing title' => [str_replace('<ezvalue key="title">Title</ezvalue>', '', $tag)];
        yield 'empty title' => [str_replace('>Title<', '>  <', $tag)];
        yield 'unknown attribute' => [str_replace('key="importance"', 'key="unknown"', $tag)];
        yield 'duplicate attribute' => [str_replace('</ezconfig>', '<ezvalue key="title">Duplicate</ezvalue></ezconfig>', $tag)];
        yield 'markup in value' => [str_replace('>Title<', '><emphasis>Title</emphasis><', $tag)];
        yield 'wrong inline mode' => [str_replace('eztemplate', 'eztemplateinline', $tag)];
        yield 'generated style' => [str_replace('name="factbox"', 'name="factbox" type="style"', $tag)];
        yield 'arbitrary class' => [str_replace('name="factbox"', 'name="factbox" class="evil"', $tag)];
        yield 'metadata attribute' => [str_replace('<ezconfig>', '<ezconfig onclick="evil">', $tag)];
        yield 'metadata namespace attribute' => [str_replace('key="title"', 'key="title" xmlns:x="urn:evil" x:key="other"', $tag)];
        yield 'orphan configuration' => ['<ezconfig><ezvalue key="title">Title</ezvalue></ezconfig>'];
        yield 'orphan value' => ['<ezvalue key="title">Title</ezvalue>'];
        yield 'orphan content' => ['<ezcontent><para>Body</para></ezcontent>'];
        yield 'missing content container' => [str_replace('<ezcontent><para>Fact</para></ezcontent>', '', $tag)];
        yield 'invalid block placement' => ['<para>'.$tag.'</para>'];
    }

    public function testTagsOutsideEditorCapabilitiesAreRejected(): void
    {
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->processor()->generated(self::xml('<eztemplate name="factbox"><ezcontent/><ezconfig><ezvalue key="title">Title</ezvalue></ezconfig></eztemplate>'), [], self::document('<para>Body</para>'), false, array_replace(self::CAPABILITIES, ['customTags' => []]));
    }

    public function testModelReceivesTheEnabledTagContractAndCanReturnAFactbox(): void
    {
        $agent = $this->createMock(\Symfony\AI\Agent\AgentInterface::class);
        $agent->expects(self::once())->method('call')->with(self::callback(static function (\Symfony\AI\Platform\Message\MessageBag $messages): bool {
            $message = iterator_to_array($messages)[0];
            $payload = json_decode($message->getContent()[0]->getText(), true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('factbox', $payload['schema']['custom_tags']);
            self::assertSame(['info', 'warning', 'critical'], $payload['schema']['custom_tags']['factbox']['attributes']['importance']['choices']);
            return true;
        }))->willReturn(new \Symfony\AI\Agent\Execution\Execution(static function (): \Generator {
            yield new \Symfony\AI\Agent\Execution\Update\Result(new \Symfony\AI\Platform\Result\TextResult(self::xml('<eztemplate name="factbox"><ezcontent><para>Travel advice.</para></ezcontent><ezconfig><ezvalue key="title">Important</ezvalue></ezconfig></eztemplate>')));
        }));
        $service = new \Wizhippo\IbexaContentAssistant\Service\RichTextContentAssistantService($this->processor(), new \Wizhippo\IbexaContentAssistant\Service\ContentAssistantService($agent), $agent);
        $result = $service->suggest('rewrite', 'Use a factbox for travel advice.', self::xml('<para>Travel advice.</para>'), 'eng-GB', self::CAPABILITIES);
        self::assertStringContainsString('<aside class="info">', $result['previewHtml']);
    }

    /** @dataProvider scalarValues */
    public function testScalarAttributeTypesAreEnforced(string $type, string $value, bool $valid): void
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('getConfiguration')->willReturn(['metrics' => ['attributes' => ['value' => ['type' => $type, 'required' => true]]]]);
        $registry = new RichTextCustomTagRegistry($provider);
        $document = self::document('<eztemplate name="metrics"><ezcontent/><ezconfig><ezvalue key="value">'.htmlspecialchars($value, ENT_XML1).'</ezvalue></ezconfig></eztemplate>');
        if (!$valid) {
            $this->expectException(\UnexpectedValueException::class);
        }
        $registry->validate($document, $registry->definitions(['customTags' => ['metrics']]));
        if ($valid) {
            self::assertSame($value, $document->getElementsByTagName('ezvalue')->item(0)->textContent);
        }
    }

    public static function scalarValues(): iterable
    {
        yield 'number' => ['number', '12.5', true];
        yield 'invalid number' => ['number', 'twelve', false];
        yield 'infinite number' => ['number', '1e999', false];
        yield 'boolean' => ['boolean', 'false', true];
        yield 'invalid boolean' => ['boolean', 'yes', false];
        yield 'link' => ['link', 'https://example.com/advice', true];
        yield 'relative link' => ['link', '/advice', true];
        yield 'unsafe link' => ['link', 'javascript:alert(1)', false];
        yield 'invented internal reference' => ['link', 'ezcontent://123', false];
    }

    public function testBooleanAndNumericDefaultsArePreserved(): void
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('getConfiguration')->willReturn(['metrics' => ['attributes' => [
            'active' => ['type' => 'boolean', 'defaultValue' => false],
            'count' => ['type' => 'number', 'defaultValue' => 0],
        ]]]);
        $registry = new RichTextCustomTagRegistry($provider);
        $document = self::document('<eztemplate name="metrics"><ezcontent/></eztemplate>');
        $registry->validate($document, $registry->definitions(['customTags' => ['metrics']]));
        self::assertSame('false', $document->getElementsByTagName('ezvalue')->item(0)->textContent);
        self::assertSame('0', $document->getElementsByTagName('ezvalue')->item(1)->textContent);
    }

    public function testUnknownAttributeTypesAreNotOfferedForGeneration(): void
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('getConfiguration')->willReturn(['special' => ['attributes' => ['asset' => ['type' => 'custom_asset_picker']]]]);
        self::assertSame([], (new RichTextCustomTagRegistry($provider))->definitions(['customTags' => ['special']]));
    }

    private static function xml(string $body): string
    {
        return '<section xmlns="http://docbook.org/ns/docbook" version="5.0-variant ezpublish-1.0">'.$body.'</section>';
    }

    private static function document(string $body): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadXML(self::xml($body));

        return $document;
    }

    private function processor(): IbexaRichTextProcessor
    {
        $provider = $this->createMock(Provider::class);
        $provider->method('getConfiguration')->willReturn(array_map(static fn (array $tag): array => $tag + ['template' => 'private-template.twig'], self::TAGS));
        $resources = dirname((new \ReflectionClass(Renderer::class))->getFileName(), 3).'/bundle/Resources/richtext';
        $inputConverter = new Xslt($resources.'/stylesheets/xhtml5/edit/docbook.xsl');
        $input = $this->createMock(InputHandlerInterface::class);
        $input->method('fromString')->willReturnCallback(static function (string $xml) use ($inputConverter): \DOMDocument {
            $document = new \DOMDocument();
            $document->loadXML($xml);
            return $document->documentElement->namespaceURI === IbexaRichTextProcessor::DOCBOOK ? $document : $inputConverter->convert($document);
        });
        $validator = new Validator([$resources.'/schemas/docbook/ezpublish.rng', $resources.'/schemas/docbook/docbook.iso.sch.xsl']);
        $twig = new Environment(new ArrayLoader([
            'factbox' => '<aside class="{{ params.importance }}"><h3>{{ params.title }}</h3>{{ content|raw }}</aside>',
            'badge' => '<mark title="{{ params.label }}">{{ content|raw }}</mark>',
        ]));
        $renderer = new Renderer($this->createMock(Repository::class), $this->createMock(ConfigResolverInterface::class), $twig, $this->createMock(PermissionResolver::class), 'tags', 'styles', 'embeds', customTagsConfiguration: ['factbox' => ['template' => 'factbox'], 'badge' => ['template' => 'badge']]);
        $output = new Aggregate();
        $output->addConverter(new Template($renderer, $output));
        $output->addConverter(new Xslt($resources.'/stylesheets/docbook/xhtml5/output/xhtml5.xsl', [['priority' => 0, 'path' => $resources.'/stylesheets/docbook/xhtml5/output/core.xsl']]));
        $output->addConverter(new Xslt($resources.'/stylesheets/xhtml5/output/fragment.xsl'));

        return new IbexaRichTextProcessor($input, $validator, new Xslt($resources.'/stylesheets/docbook/xhtml5/edit/xhtml5.xsl', [['priority' => 0, 'path' => $resources.'/stylesheets/docbook/xhtml5/edit/core.xsl']]), $output, customTags: new RichTextCustomTagRegistry($provider));
    }
}
