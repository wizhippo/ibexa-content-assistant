<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Service;

use Ibexa\Contracts\Core\Limitation\Target\Builder\VersionBuilder;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Symfony\Bundle\SecurityBundle\Security;
use Wizhippo\IbexaContentAssistant\Security\ContentAssistantPolicy;

final class ContentAssistantAccess
{
    /** @param array<string, list<string>> $allowedFields */
    public function __construct(
        private readonly Repository $repository,
        private readonly Security $security,
        private readonly ContentAssistantPolicy $policy,
        private readonly bool $enabled,
        private readonly array $allowedFields,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->enabled
            && $this->security->isGranted('IS_AUTHENTICATED_REMEMBERED')
            && $this->policy->isGranted(ContentAssistantPolicy::FUNCTION_USE);
    }

    /** @return array<string, string> Field identifier => field label */
    public function fields(Content $content, ContentType $contentType, string $language): array
    {
        if (!$this->isAvailable() || !$content->getVersionInfo()->isDraft()) {
            return [];
        }

        $fields = [];
        foreach ($contentType->getFieldDefinitions() as $definition) {
            if (!in_array($definition->getIdentifier(), $this->allowedFields[$contentType->getIdentifier()] ?? [], true)
                || !in_array($definition->getFieldTypeIdentifier(), ['ibexa_string', 'ibexa_text', 'ibexa_richtext'], true)
                || (!$definition->isTranslatable() && $language !== $content->getContentInfo()->getMainLanguageCode())) {
                continue;
            }

            $field = new Field([
                'fieldDefIdentifier' => $definition->getIdentifier(),
                'fieldTypeIdentifier' => $definition->getFieldTypeIdentifier(),
                'languageCode' => $language,
            ]);
            $target = (new VersionBuilder())
                ->updateFields([$field])
                ->updateFieldsTo($language, [$field])
                ->build();
            if ($this->repository->getPermissionResolver()->canUser('content', 'edit', $content, [$target])) {
                $fields[$definition->getIdentifier()] = $definition->getName($language) ?? $definition->getIdentifier();
            }
        }

        return $fields;
    }
}
