<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Service;

use Ibexa\Contracts\FieldTypeRichText\RichText\InputHandlerInterface;
use Ibexa\FieldTypeRichText\RichText\Converter\Xslt;
use Ibexa\FieldTypeRichText\RichText\Renderer;
use Ibexa\FieldTypeRichText\RichText\Validator\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\Yaml\Yaml;
use Wizhippo\IbexaContentAssistant\Exception\ContextWindowExceeded;
use Wizhippo\IbexaContentAssistant\Exception\InvalidRichTextSuggestion;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantBudgetProcessor;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantService;
use Wizhippo\IbexaContentAssistant\Service\IbexaRichTextProcessor;
use Wizhippo\IbexaContentAssistant\Service\RichTextContentAssistantService;

final class RichTextRepairTest extends TestCase
{
    private const array CAPABILITIES = ['headingLevels' => [1, 2, 3], 'tables' => true, 'lists' => true];
    private const string URL = 'https://example.com/contact?target=https%3A%2F%2Fsupplier.example&tracking=abc';

    public function testOneRepairAddressesInventedElementsAndMissingDuplicateLinksTogether(): void
    {
        $processor = $this->processor();
        $source = self::xml('<para>Contact '.self::link().' and '.self::link().'</para>');
        $calls = 0;
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::exactly(2))->method('call')->willReturnCallback(function (MessageBag $messages) use (&$calls, $source): Execution {
            $payload = self::payload($messages);
            self::assertSame('ibexa_docbook', $payload['response_format']);
            self::assertSame('Output Markdown.', $payload['action_context']['configuration']['prompt']);
            self::assertStringContainsString('override action_context', $payload['instruction_priority']);
            self::assertSame([['url' => self::URL, 'occurrences' => 2]], $payload['required_links']);
            self::assertStringContainsString('Contact', $payload['source_docbook']);
            // Exercise the actual system-prompt and budget processors on both attempts.
            $input = new Input('test', clone $messages);
            $prompt = Yaml::parseFile(dirname(__DIR__, 2).'/config/ai.yaml')['ai']['agent']['wizhippo_ibexa_content_assistant']['prompt'];
            (new SystemPromptInputProcessor($prompt))->processInput($input);
            self::assertStringContainsString('take precedence over all configured action_context templates', $input->getMessageBag()->getSystemMessage()->getContent());
            self::assertStringContainsString('even if an action template requests Markdown', $input->getMessageBag()->getSystemMessage()->getContent());
            (new ContentAssistantBudgetProcessor(100000, 10000, 1000, 50000))->processInput($input);
            self::assertSame(10000, $input->getOptions()['max_output_tokens']);
            if (++$calls === 1) {
                self::assertArrayNotHasKey('repair_feedback', $payload);
                return self::execution(self::xml('<para>Contact<br-placeholder/><bracket_placeholder/> '.self::link().'</para>'));
            }
            $feedback = $payload['repair_feedback'];
            self::assertFalse($feedback['invalid_xml']);
            self::assertSame([
                ['name' => 'br-placeholder', 'namespace' => IbexaRichTextProcessor::DOCBOOK],
                ['name' => 'bracket_placeholder', 'namespace' => IbexaRichTextProcessor::DOCBOOK],
            ], $feedback['unsupported_elements']);
            self::assertSame([['url' => self::URL, 'occurrences' => 2, 'missing_occurrences' => 1]], $feedback['missing_required_links']);
            self::assertSame([], $feedback['excess_required_links']);
            self::assertStringNotContainsString('rejected_docbook', json_encode($payload, JSON_THROW_ON_ERROR));

            return self::execution(str_replace(['<para>Contact ', '</para>'], ['<para><literallayout class="normal">Contact&#10;', '</literallayout></para>'], $source));
        });
        $rendered = $this->service($processor, $agent)->suggest('rewrite', 'Restructure supplier information.', $source, 'eng-GB', self::CAPABILITIES, ['configuration' => ['prompt' => 'Output Markdown.']]);
        self::assertSame(2, substr_count($rendered['editXhtml'], 'tracking=abc'));
        self::assertStringNotContainsString('placeholder', $rendered['editXhtml']);
        self::assertStringContainsString('Contact', $rendered['previewHtml']);
        self::assertStringContainsString('<br', $rendered['editXhtml']);
    }

    public function testInventedPlaceholdersRemainRejectedAfterTheSingleRetry(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::exactly(2))->method('call')->willReturnOnConsecutiveCalls(
            self::execution(self::xml('<para>Body<br-placeholder/></para>')),
            self::execution(self::xml('<para>Body<bracket_placeholder/></para>')),
        );
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->service($this->processor(), $agent)->suggest('rewrite', '', self::xml('<para>Body</para>'), 'eng-GB', self::CAPABILITIES);
    }

    /** @dataProvider contactUrls */
    public function testMissingLinksAreRepairedWithoutLoggingTheUrlOrProse(string $url): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('RichText generation rejected: required link counts changed.', [
            'category' => 'missing_required_links', 'missing_occurrences' => 1, 'excess_occurrences' => 0,
        ]);
        $agent = $this->createMock(AgentInterface::class);
        $calls = 0;
        $agent->expects(self::exactly(2))->method('call')->willReturnCallback(static function (MessageBag $messages) use (&$calls, $url): Execution {
            if (++$calls === 1) {
                return self::execution(self::xml('<para>PRIVATE_GENERATED_PROSE</para>'));
            }
            self::assertSame([['url' => $url, 'occurrences' => 1, 'missing_occurrences' => 1]], self::payload($messages)['repair_feedback']['missing_required_links']);
            return self::execution(self::xml('<para>'.self::link($url).'</para>'));
        });
        $result = $this->service($this->processor($logger), $agent)->suggest('rewrite', '', self::xml('<para>'.self::link($url).'</para>'), 'eng-GB', self::CAPABILITIES);
        self::assertStringContainsString(htmlspecialchars($url, ENT_XML1), $result['editXhtml']);
    }

    public static function contactUrls(): iterable
    {
        yield 'tracking URL' => [self::URL];
        yield 'email' => ['mailto:supplier@example.com'];
        yield 'phone' => ['tel:+18005550123'];
    }

    public function testUnsupportedElementLogsOnlySafeVocabulary(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('RichText generation rejected: unsupported element.', [
            'category' => 'unsupported_element', 'element' => '[unrecognized]', 'namespace' => '[unrecognized]', 'line' => 1,
        ]);
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->processor($logger)->generated(self::xml('<secret:PRIVATE_ELEMENT xmlns:secret="https://user:password@example.com">PRIVATE_PROSE</secret:PRIVATE_ELEMENT>'), [], self::document(self::xml('<para>Body</para>')), false, self::CAPABILITIES);
    }

    public function testRepairFeedbackNeverDisclosesProtectedNodesOrTheirLinks(): void
    {
        $source = self::xml('<para>'.self::link().'</para><eztemplate name="private"><ezcontent><para><link xlink:href="https://example.com/PRIVATE_LINK">Private</link></para></ezcontent><ezconfig><ezvalue key="secret">PRIVATE_TOKEN</ezvalue></ezconfig></eztemplate>');
        $agent = $this->createMock(AgentInterface::class);
        $calls = 0;
        $agent->expects(self::exactly(2))->method('call')->willReturnCallback(static function (MessageBag $messages) use (&$calls): Execution {
            $payload = self::payload($messages);
            self::assertStringNotContainsString('PRIVATE_', json_encode($payload, JSON_THROW_ON_ERROR));
            self::assertSame([['url' => self::URL, 'occurrences' => 1]], $payload['required_links']);
            // Keep placeholders intact so the second rejection reaches link checks.
            $output = str_replace(self::link(), 'Removed link', $payload['source_docbook']);
            if (++$calls === 1) {
                $output = str_replace('</section>', '<br-placeholder/></section>', $output);
            } else {
                self::assertSame([['url' => self::URL, 'occurrences' => 1, 'missing_occurrences' => 1]], $payload['repair_feedback']['missing_required_links']);
            }
            return self::execution($output);
        });
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->service($this->processor(), $agent)->suggest('rewrite', '', $source, 'eng-GB', self::CAPABILITIES);
    }

    /** @dataProvider unsafeRepairs */
    public function testRepairMustStillPassEveryValidationSafeguard(string $body, array $capabilities): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::exactly(2))->method('call')->willReturnOnConsecutiveCalls(
            self::execution(self::xml('<para>Body<br-placeholder/></para>')),
            self::execution(self::xml($body)),
        );
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->service($this->processor(), $agent)->suggest('rewrite', '', self::xml('<para>Body</para><ezembed xlink:href="ezcontent://123" view="embed"/>'), 'eng-GB', $capabilities);
    }

    public static function unsafeRepairs(): iterable
    {
        yield 'script' => ['<script>evil</script>', self::CAPABILITIES];
        yield 'unsafe attribute' => ['<para onclick="evil">Body</para>', self::CAPABILITIES];
        yield 'invalid schema' => ['<informaltable><para>Body</para></informaltable>', self::CAPABILITIES];
        yield 'disabled heading' => ['<title ezxhtml:level="3">Heading</title>', array_replace(self::CAPABILITIES, ['headingLevels' => [1]])];
        yield 'unconfigured tag' => ['<eztemplate name="unconfigured"><ezcontent/></eztemplate>', self::CAPABILITIES];
        yield 'removed protected content' => ['<para>Removed embed</para>', self::CAPABILITIES];
    }

    public function testUnsafeUrlIsRejectedOnRepairEvenWithIntactProtectedContent(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $calls = 0;
        $agent->expects(self::exactly(2))->method('call')->willReturnCallback(static function (MessageBag $messages) use (&$calls): Execution {
            $payload = self::payload($messages);
            $extra = ++$calls === 1 ? '<br-placeholder/>' : '<para><link xlink:href="javascript:evil">Link</link></para>';
            return self::execution(str_replace('</section>', $extra.'</section>', $payload['source_docbook']));
        });
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->service($this->processor(), $agent)->suggest('rewrite', '', self::xml('<para>Body</para><ezembed xlink:href="ezcontent://123" view="embed"/>'), 'eng-GB', self::CAPABILITIES);
    }

    public function testValidFirstResponseDoesNotRetry(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->willReturn(self::execution(self::xml('<para>Body</para>')));
        $result = $this->service($this->processor(), $agent)->suggest('rewrite', '', self::xml('<para>Body</para>'), 'eng-GB', self::CAPABILITIES);
        self::assertStringContainsString('Body', $result['previewHtml']);
    }

    public function testProviderErrorsAreNotRetried(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->willThrowException(new \RuntimeException('Provider error'));
        $this->expectException(\RuntimeException::class);
        $this->service($this->processor(), $agent)->suggest('rewrite', '', self::xml('<para>Body</para>'), 'eng-GB', self::CAPABILITIES);
    }

    public function testRepairRemainsSubjectToTheContextBudget(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $budget = null;
        $providerCalls = 0;
        $agent->expects(self::exactly(2))->method('call')->willReturnCallback(static function (MessageBag $messages) use (&$budget, &$providerCalls): Execution {
            $input = new Input('test', $messages);
            $budget ??= new ContentAssistantBudgetProcessor(strlen($messages->getUserMessage()->asText()) + 301, 200, 100, 50000);
            $budget->processInput($input);
            ++$providerCalls;
            return self::execution(self::xml('<para>Body<br-placeholder/></para>'));
        });
        try {
            $this->service($this->processor(), $agent)->suggest('rewrite', '', self::xml('<para>Body</para>'), 'eng-GB', self::CAPABILITIES);
            self::fail('Repair feedback must be included in the context budget.');
        } catch (ContextWindowExceeded) {
            self::assertSame(1, $providerCalls);
        }
    }

    public function testFeedbackReportsExcessCountsAndMalformedXmlWithoutEchoingIt(): void
    {
        $processor = $this->processor();
        $required = [['url' => self::URL, 'occurrences' => 1]];
        $feedback = $processor->repairFeedback(self::xml('<para>'.self::link().self::link().'</para>'), $required, self::CAPABILITIES);
        self::assertSame([], $feedback['missing_required_links']);
        self::assertSame([['url' => self::URL, 'occurrences' => 1, 'excess_occurrences' => 1]], $feedback['excess_required_links']);
        $feedback = $processor->repairFeedback('<para>PRIVATE_PROSE', $required, self::CAPABILITIES);
        self::assertTrue($feedback['invalid_xml']);
        self::assertSame([['url' => self::URL, 'occurrences' => 1, 'missing_occurrences' => 1]], $feedback['missing_required_links']);
        self::assertStringNotContainsString('PRIVATE_PROSE', json_encode($feedback, JSON_THROW_ON_ERROR));
    }

    public function testUnsupportedElementFeedbackIsBounded(): void
    {
        $xml = self::xml(implode('', array_map(static fn (int $i): string => '<invented_'.$i.str_repeat('x', 200).'/>', range(1, 50))));
        $feedback = $this->processor()->repairFeedback($xml, [], self::CAPABILITIES);
        self::assertCount(32, $feedback['unsupported_elements']);
        foreach ($feedback['unsupported_elements'] as $element) {
            self::assertLessThanOrEqual(128, strlen($element['name']));
        }
    }

    private static function payload(MessageBag $messages): array
    {
        return json_decode($messages->getUserMessage()->asText(), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function execution(string $xml): Execution
    {
        return new Execution(static function () use ($xml): \Generator {
            yield new Result(new TextResult($xml));
        });
    }

    private static function link(?string $url = null): string
    {
        return '<link xlink:href="'.htmlspecialchars($url ?? self::URL, ENT_XML1).'">Supplier contact</link>';
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

    private function service(IbexaRichTextProcessor $processor, AgentInterface $agent): RichTextContentAssistantService
    {
        return new RichTextContentAssistantService($processor, new ContentAssistantService($agent), $agent);
    }

    private function processor(?LoggerInterface $logger = null): IbexaRichTextProcessor
    {
        $resources = dirname((new \ReflectionClass(Renderer::class))->getFileName(), 3).'/bundle/Resources/richtext';
        $input = $this->createMock(InputHandlerInterface::class);
        $input->method('fromString')->willReturnCallback(self::document(...));
        $validator = new Validator([$resources.'/schemas/docbook/ezpublish.rng', $resources.'/schemas/docbook/docbook.iso.sch.xsl']);
        $edit = new Xslt($resources.'/stylesheets/docbook/xhtml5/edit/xhtml5.xsl', [['priority' => 0, 'path' => $resources.'/stylesheets/docbook/xhtml5/edit/core.xsl']]);
        $output = new Xslt($resources.'/stylesheets/docbook/xhtml5/output/xhtml5.xsl', [['priority' => 0, 'path' => $resources.'/stylesheets/docbook/xhtml5/output/core.xsl']]);
        return new IbexaRichTextProcessor($input, $validator, $edit, $output, logger: $logger);
    }
}
