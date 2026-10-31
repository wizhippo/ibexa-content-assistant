<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Service;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ContentAssistantService
{
    public const array OPERATIONS = ['author', 'continue', 'rewrite', 'shorten', 'expand', 'correct'];
    public const int MAX_SOURCE_LENGTH = 12000;
    public const int MAX_INSTRUCTIONS_LENGTH = 2000;
    public const int MAX_OUTPUT_LENGTH = 16000;

    public function __construct(
        #[Autowire(service: 'ai.agent.wizhippo_ibexa_content_assistant', lazy: true)] private readonly AgentInterface $agent,
    ) {
    }

    public function suggest(string $operation, string $instructions, string $source, string $language, array $context = []): string
    {
        $this->validate($operation, $instructions, $source, $language);

        $messages = new MessageBag(Message::ofUser(json_encode([
            'operation' => $operation,
            'language' => $language,
            'editorial_instructions' => $instructions,
            'source_text' => $source,
        ] + ($context === [] ? [] : ['action_context' => $context]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));

        $result = $this->agent->call($messages)->asText();
        if (trim($result) === '' || mb_strlen($result) > self::MAX_OUTPUT_LENGTH) {
            throw new \UnexpectedValueException('The provider returned an unusable suggestion.');
        }

        return trim($result);
    }

    public function validate(string $operation, string $instructions, string $source, string $language): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException('Unsupported operation.');
        }
        if (mb_strlen($source) > self::MAX_SOURCE_LENGTH || mb_strlen($instructions) > self::MAX_INSTRUCTIONS_LENGTH) {
            throw new \InvalidArgumentException('Source text or instructions exceed the allowed length.');
        }
        if ($operation === 'author' ? trim($instructions) === '' : trim($source) === '') {
            throw new \InvalidArgumentException('Authoring requires instructions; rewriting requires source text.');
        }
        if (!preg_match('/^[a-z]{3}-[A-Z]{2}$/D', $language)) {
            throw new \InvalidArgumentException('Invalid content language.');
        }
    }
}
