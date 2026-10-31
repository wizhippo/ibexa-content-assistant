<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantService;

final class ContentAssistantServiceTest extends TestCase
{
    public function testSendsOnlyExplicitEditorialContext(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')
            ->with(self::callback(static function (MessageBag $messages): bool {
                $items = iterator_to_array($messages);
                self::assertInstanceOf(UserMessage::class, $items[0]);
                $content = $items[0]->getContent()[0];
                self::assertInstanceOf(Text::class, $content);
                $payload = json_decode($content->getText(), true, 512, JSON_THROW_ON_ERROR);

                return count($items) === 1 && $payload === [
                    'operation' => 'rewrite',
                    'language' => 'eng-GB',
                    'editorial_instructions' => 'Use a friendly tone.',
                    'source_text' => 'Commission is 12%.',
                ];
            }))
            ->willReturn(self::execution('  Your commission is 12%.  '));

        self::assertSame('Your commission is 12%.', (new ContentAssistantService($agent))->suggest(
            'rewrite', 'Use a friendly tone.', 'Commission is 12%.', 'eng-GB'
        ));
    }

    /** @dataProvider invalidRequests */
    public function testRejectsInvalidInputWithoutCallingProvider(string $operation, string $instructions, string $source, string $language): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::never())->method('call');
        $this->expectException(\InvalidArgumentException::class);

        (new ContentAssistantService($agent))->suggest($operation, $instructions, $source, $language);
    }

    public static function invalidRequests(): iterable
    {
        yield 'unsupported action' => ['publish', '', 'Text', 'eng-GB'];
        yield 'author needs instructions' => ['author', '  ', '', 'eng-GB'];
        yield 'rewrite needs source' => ['rewrite', '', '  ', 'eng-GB'];
        yield 'source too long' => ['rewrite', '', str_repeat('é', 12001), 'eng-GB'];
        yield 'instructions too long' => ['author', str_repeat('a', 2001), '', 'eng-GB'];
        yield 'invalid language' => ['rewrite', '', 'Text', 'English'];
    }

    /** @dataProvider invalidSuggestions */
    public function testRejectsUnusableProviderOutput(string $output): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('call')->willReturn(self::execution($output));
        $this->expectException(\UnexpectedValueException::class);

        (new ContentAssistantService($agent))->suggest('author', 'Write a welcome.', '', 'eng-GB');
    }

    public static function invalidSuggestions(): iterable
    {
        yield 'empty output' => ['   '];
        yield 'oversized output' => [str_repeat('a', 16001)];
    }

    public function testExtractsVisibleTextFromReasoningModelResponse(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('call')->willReturn(new Execution(static function (): \Generator {
            yield new Result(new MultiPartResult([
                new ThinkingResult('Private reasoning must not become article content.'),
                new TextResult('Welcome to TravelOnly.'),
            ]));
        }));

        self::assertSame('Welcome to TravelOnly.', (new ContentAssistantService($agent))->suggest(
            'author', 'Write a welcome.', '', 'eng-GB'
        ));
    }

    public function testCombinesMultipleVisibleTextParts(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('call')->willReturn(new Execution(static function (): \Generator {
            yield new Result(new MultiPartResult([
                new ThinkingResult(),
                new TextResult('First paragraph. '),
                new TextResult('Second paragraph.'),
            ]));
        }));

        self::assertSame('First paragraph. Second paragraph.', (new ContentAssistantService($agent))->suggest(
            'author', 'Write a welcome.', '', 'eng-GB'
        ));
    }

    private static function execution(string $text): Execution
    {
        return new Execution(static function () use ($text): \Generator {
            yield new Result(new TextResult($text));
        });
    }
}
