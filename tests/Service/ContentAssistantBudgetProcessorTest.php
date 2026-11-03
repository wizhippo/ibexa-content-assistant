<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Agent\Output;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Wizhippo\IbexaContentAssistant\Exception\ContextWindowExceeded;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantBudgetProcessor;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantService;

final class ContentAssistantBudgetProcessorTest extends TestCase
{
    public function testCombinedInputReservesOutputAndOverheadAtTheBoundary(): void
    {
        $processor = new ContentAssistantBudgetProcessor(100, 20, 10, 1000);
        $input = new Input('test', new MessageBag(Message::forSystem(str_repeat('s', 20)), Message::ofUser(str_repeat('é', 25))));
        $processor->processInput($input);
        self::assertSame(20, $input->getOptions()['max_output_tokens']);

        $input->getMessageBag()->add(Message::ofUser('x'));
        $this->expectException(ContextWindowExceeded::class);
        $processor->processInput($input);
    }

    public function testSystemPromptAndActionContextAreCheckedBeforeProviderInvocation(): void
    {
        $platform = $this->createMock(PlatformInterface::class);
        $platform->expects(self::never())->method('invoke');
        $agent = new Agent($platform, 'test', [
            new SystemPromptInputProcessor(str_repeat('system ', 100)),
            new ContentAssistantBudgetProcessor(1000, 200, 100, 10000),
        ]);
        $this->expectException(ContextWindowExceeded::class);
        (new ContentAssistantService($agent))->suggest('rewrite', 'Instructions', 'Source', 'eng-GB', [
            'configuration' => ['prompt' => str_repeat('action ', 100)],
        ]);
    }

    public function testLargeTextIsAcceptedWhenItFitsTheConfiguredModel(): void
    {
        $processor = new ContentAssistantBudgetProcessor(1050000, 128000, 1024, 4194304);
        $input = new Input('test', new MessageBag(Message::ofUser(str_repeat('Long article. ', 20000))));
        $processor->processInput($input);
        self::assertSame(128000, $input->getOptions()['max_output_tokens']);
        $processor->processOutput(new Output('test', new TextResult(str_repeat('Long response. ', 20000)), $input->getMessageBag()));
    }

    /** @dataProvider incompleteReasons */
    public function testRejectsPartialResponses(string $reason): void
    {
        $result = new TextResult('A partial response that looks usable.');
        $result->getMetadata()->add('finish_reason', new FinishReason(FinishReasonCase::from($reason), $reason));
        $this->expectException(\UnexpectedValueException::class);
        (new ContentAssistantBudgetProcessor(1000, 200, 100, 10000))->processOutput(new Output('test', $result, new MessageBag()));
    }

    public static function incompleteReasons(): iterable
    {
        yield ['length'];
        yield ['content-filter'];
        yield ['other'];
    }

    public function testRejectsProviderOutputAboveItsTokenBudget(): void
    {
        $result = new TextResult('Response');
        $result->getMetadata()->add('token_usage', new TokenUsage(completionTokens: 201));
        $this->expectException(\UnexpectedValueException::class);
        (new ContentAssistantBudgetProcessor(1000, 200, 100, 10000))->processOutput(new Output('test', $result, new MessageBag()));
    }

    public function testResponseResourceLimitIsIndependentOfTokenBudget(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ContentAssistantBudgetProcessor(1000, 200, 100, 10))->processOutput(new Output('test', new TextResult(str_repeat('é', 6)), new MessageBag()));
    }

    public function testInvalidModelBudgetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ContentAssistantBudgetProcessor(1000, 900, 100, 10000);
    }
}
