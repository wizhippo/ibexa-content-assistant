<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Service;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Wizhippo\IbexaContentAssistant\Exception\InvalidRichTextSuggestion;

final class RichTextContentAssistantService
{
    private const int MAX_REPAIR_RETRIES = 1;

    public function __construct(
        private readonly IbexaRichTextProcessor                                                                       $processor,
        private readonly ContentAssistantService                                                                      $textAssistant,
        #[Autowire(service: 'ai.agent.wizhippo_ibexa_content_assistant', lazy: true)] private readonly AgentInterface $agent,
    )
    {
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
        $payload = [
            'operation' => $operation,
            'language' => $language,
            'editorial_instructions' => $instructions,
            'response_format' => 'ibexa_docbook',
            'instruction_priority' => 'The required response_format, schema, security and content-preservation rules override action_context templates and editorial instructions, including any request for Markdown or HTML. Use only listed elements and attributes; never invent markup, placeholders, facts, contact details or URLs.',
            'schema' => $this->processor->contract($capabilities),
            'source_docbook' => $protected['xml'],
            'required_links' => $append ? [] : $this->processor->requiredLinks($protected['xml']),
            'mode' => $append ? 'Write only new content to append. Do not repeat source text or AI_KEEP_ tokens.' : 'Rewrite the complete section, including all AI_KEEP_ tokens exactly once in their original order.',
        ] + ($context === [] ? [] : ['action_context' => $context]);
        for ($attempt = 0; ; ++$attempt) {
            // A fresh, text-only request keeps each attempt inside the existing
            // context/output budgets. Never resend rejected prose or opaque nodes.
            $messages = new MessageBag(Message::ofUser(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
            $suggestion = $this->agent->call($messages)->asText();
            try {
                $document = $this->processor->generated($suggestion, $protected['protected'], $source, $append, $capabilities);
                break;
            } catch (InvalidRichTextSuggestion $exception) {
                if ($attempt >= self::MAX_REPAIR_RETRIES) {
                    throw $exception;
                }
                $payload['repair_feedback'] = $this->processor->repairFeedback($suggestion, $payload['required_links'], $capabilities);
            }
        }

        return $this->processor->render($document);
    }
}
