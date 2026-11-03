<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Service;

use Symfony\AI\Agent\Attribute\AsInputProcessor;
use Symfony\AI\Agent\Attribute\AsOutputProcessor;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessorInterface;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Platform\FinishReason\FinishReason;
use Symfony\AI\Platform\FinishReason\FinishReasonCase;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Wizhippo\IbexaContentAssistant\Exception\ContextWindowExceeded;

// Run after the system prompt and other normal input processors.
#[AsInputProcessor(agent: 'ai.agent.wizhippo_ibexa_content_assistant', priority: -10000)]
#[AsOutputProcessor(agent: 'ai.agent.wizhippo_ibexa_content_assistant', priority: -10000)]
final class ContentAssistantBudgetProcessor implements InputProcessorInterface, OutputProcessorInterface
{
    public function __construct(
        #[Autowire(param: 'wizhippo_ibexa_content_assistant.context_window')] private readonly int $contextWindow,
        #[Autowire(param: 'wizhippo_ibexa_content_assistant.max_output_tokens')] private readonly int $maxOutputTokens,
        #[Autowire(param: 'wizhippo_ibexa_content_assistant.token_safety_margin')] private readonly int $safetyMargin,
        #[Autowire(param: 'wizhippo_ibexa_content_assistant.max_response_bytes')] private readonly int $maxResponseBytes,
    ) {
        if ($contextWindow <= 0 || $maxOutputTokens <= 0 || $safetyMargin < 0 || $maxResponseBytes <= 0
            || $maxOutputTokens >= $contextWindow || $safetyMargin >= $contextWindow - $maxOutputTokens) {
            throw new \InvalidArgumentException('Configure a context_window larger than max_output_tokens plus token_safety_margin for the content assistant model.');
        }
    }

    public function processInput(Input $input): void
    {
        $inputBudget = $this->contextWindow - $this->maxOutputTokens - $this->safetyMargin;
        $estimatedTokens = 0;
        foreach ($input->getMessageBag() as $message) {
            // The assistant sends only system and user text. Reject extensions we cannot budget.
            if ($message instanceof SystemMessage && is_string($message->getContent())) {
                $text = $message->getContent();
            } elseif ($message instanceof UserMessage) {
                $text = '';
                foreach ($message->getContent() as $part) {
                    if (!$part instanceof Text) {
                        throw new \InvalidArgumentException('The content assistant budget supports text messages only.');
                    }
                    $text .= $part->getText();
                }
            } else {
                throw new \InvalidArgumentException('The content assistant budget supports system and user text messages only.');
            }
            // No tokenizer metadata is available from the Responses fallback catalog.
            // One token per UTF-8 byte conservatively budgets byte-based tokenizers;
            // the configurable margin reserves message framing and provider overhead.
            $estimatedTokens += strlen($text);
        }
        if ($estimatedTokens > $inputBudget) {
            throw new ContextWindowExceeded(sprintf(
                'The complete request exceeds the configured model context budget (%d estimated input tokens available after reserving output and overhead). Reduce the content or instructions, or ask an administrator to check the model limits.',
                $inputBudget,
            ));
        }

        // Keep the budget sent to the provider identical to the space reserved above.
        $input->setOptions(array_replace($input->getOptions(), ['max_output_tokens' => $this->maxOutputTokens]));
    }

    public function processOutput(Output $output): void
    {
        $result = $output->getResult();
        $finishReason = $result->getMetadata()->get('finish_reason');
        if ($finishReason instanceof FinishReason && !$finishReason->is(FinishReasonCase::STOP)) {
            throw new \UnexpectedValueException('The provider did not return a complete suggestion.');
        }
        $usage = $result->getMetadata()->get('token_usage');
        if ($usage instanceof TokenUsageInterface && $usage->getCompletionTokens() > $this->maxOutputTokens) {
            throw new \UnexpectedValueException('The provider exceeded the configured output token budget.');
        }
        $text = match (true) {
            $result instanceof TextResult => $result->getContent(),
            $result instanceof MultiPartResult => $result->asText(),
            default => '',
        };
        if (trim($text) === '' || strlen($text) > $this->maxResponseBytes) {
            throw new \UnexpectedValueException('The provider returned an unusable suggestion.');
        }
    }
}
