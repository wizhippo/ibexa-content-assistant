<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Ibexa;

use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\TwigComponents\ComponentInterface;
use Twig\Environment;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantAccess;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantActionRegistry;

final class ContentAssistantComponent implements ComponentInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ContentAssistantAccess $access,
        private readonly ContentAssistantActionRegistry $actions,
    ) {
    }

    public function render(array $parameters = []): string
    {
        $content = $parameters['content'] ?? null;
        if (!$content instanceof Content || !$this->access->isAvailable()) {
            return '';
        }
        $fields = $this->access->fields($content, $parameters['content_type'], $parameters['language']->languageCode);
        if ($fields === []) {
            return '';
        }

        return $this->twig->render('@WizhippoIbexaContentAssistant/admin/content_assistant.html.twig', $parameters + ['assistant_fields' => $fields, 'assistant_actions' => $this->actions->all(true)]);
    }
}
