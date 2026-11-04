<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Service;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class RichTextContentAssistantService
{
    public function __construct(
        private readonly IbexaRichTextProcessor $processor,
        private readonly ContentAssistantService $textAssistant,
        #[Autowire(service: 'ai.agent.wizhippo_ibexa_content_assistant', lazy: true)] private readonly AgentInterface $agent,
    ) {
    }

    /** @param array<string, mixed> $capabilities */
    public function validate(string $operation, string $instructions, string $xml, string $language, array $capabilities): void
    {
        $document = $this->processor->read($xml);
        // RichText is bounded as XML by the processor, not as extracted plain text.
        // An embed-only document is valid editorial content as well.
        $hasContent = trim($document->textContent) !== '' || $document->getElementsByTagName('ezembed')->length > 0
            || $document->getElementsByTagName('ezembedinline')->length > 0
            || $document->getElementsByTagName('eztemplate')->length > 0
            || $document->getElementsByTagName('eztemplateinline')->length > 0
            || $document->getElementsByTagName('informaltable')->length > 0;
        $this->textAssistant->validate($operation, $instructions, $hasContent ? 'RichText document' : '', $language);
        if (!isset($capabilities['headingLevels'], $capabilities['tables'], $capabilities['lists'])
            || !is_array($capabilities['headingLevels']) || !array_is_list($capabilities['headingLevels'])
            || !is_bool($capabilities['tables']) || !is_bool($capabilities['lists'])) {
            throw new \InvalidArgumentException('The editor capabilities are missing or invalid. Reload the editor.');
        }
        if (isset($capabilities['customTags']) && (!is_array($capabilities['customTags']) || !array_is_list($capabilities['customTags']))) {
            throw new \InvalidArgumentException('Invalid custom tag capabilities. Reload the editor.');
        }
        foreach ($capabilities['customTags'] ?? [] as $tag) {
            if (!is_string($tag) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', $tag)) {
                throw new \InvalidArgumentException('Invalid custom tag identifier.');
            }
        }
        foreach ($capabilities['headingLevels'] as $level) {
            if (!is_int($level) || $level < 1 || $level > 6) {
                throw new \InvalidArgumentException('Unsupported heading level.');
            }
        }
    }

    /**
     * @param array<string, mixed> $capabilities
     * @param array<string, mixed> $context
     * @return array{editXhtml: string, previewHtml: string}
     */
    public function suggest(string $operation, string $instructions, string $xml, string $language, array $capabilities, array $context = []): array
    {
        $this->validate($operation, $instructions, $xml, $language, $capabilities);
        $source = $this->processor->read($xml);
        $protected = $this->processor->protect($source);
        $append = in_array($operation, ['author', 'continue'], true);
        $messages = new MessageBag(Message::ofUser(json_encode([
            'operation' => $operation,
            'language' => $language,
            'editorial_instructions' => $instructions,
            'response_format' => 'ibexa_docbook',
            'schema' => $this->processor->contract($capabilities),
            'source_docbook' => $protected['xml'],
            'required_links' => $append ? [] : $this->processor->requiredLinks($protected['xml']),
            'mode' => $append ? 'Write only new content to append. Do not repeat source text or AI_KEEP_ tokens.' : 'Rewrite the complete section, including all AI_KEEP_ tokens exactly once in their original order.',
        ] + ($context === [] ? [] : ['action_context' => $context]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        $xml = $this->agent->call($messages)->asText();
        $document = $this->processor->generated($xml, $protected['protected'], $source, $append, $capabilities);

        return $this->processor->render($document);
    }
}
