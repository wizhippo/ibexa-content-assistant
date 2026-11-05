<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Service;

use Ibexa\Contracts\FieldTypeRichText\Configuration\Provider;
use Ibexa\FieldTypeRichText\Configuration\Provider\CustomTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Editorial contracts from the same SiteAccess-aware provider used by the editor. */
final class RichTextCustomTagRegistry
{
    public const array ELEMENTS = ['eztemplate', 'eztemplateinline', 'ezcontent', 'ezconfig', 'ezvalue'];

    public function __construct(
        #[Autowire(service: CustomTag::class)] private readonly Provider $provider,
    )
    {
    }

    /**
     * @param array<string, mixed> $capabilities
     * @return array<string, array<string, mixed>>
     */
    public function definitions(array $capabilities): array
    {
        $definitions = [];
        foreach ($this->provider->getConfiguration() as $name => $configuration) {
            if (!in_array($name, $capabilities['customTags'] ?? [], true)) {
                continue;
            }
            $attributes = [];
            foreach ($configuration['attributes'] ?? [] as $key => $attribute) {
                if (!in_array($attribute['type'], ['string', 'number', 'boolean', 'choice', 'link'], true)) {
                    // A custom attribute type needs a dedicated validator before it can be generated.
                    continue 2;
                }
                $attributes[$key] = [
                    'type' => $attribute['type'],
                    'required' => $attribute['required'] ?? false,
                    'label' => $attribute['label'] ?? $key,
                    'default' => $attribute['defaultValue'] ?? null,
                ];
                if ($attribute['type'] === 'choice') {
                    $attributes[$key]['choices'] = array_values($attribute['choices'] ?? []);
                }
            }
            $inline = (bool)($configuration['isInline'] ?? false);
            $definitions[$name] = [
                'element' => $inline ? 'eztemplateinline' : 'eztemplate',
                'is_inline' => $inline,
                'label' => $configuration['label'] ?? $name,
                'description' => $configuration['description'] ?? '',
                'attributes' => $attributes,
            ];
        }

        return $definitions;
    }

    /**
     * Validate new custom-tag markup, inserting configured attribute defaults.
     * @param array<string, array<string, mixed>> $definitions
     */
    public function validate(\DOMDocument $document, array $definitions): void
    {
        foreach (iterator_to_array($document->getElementsByTagName('*')) as $element) {
            if ($element->namespaceURI !== IbexaRichTextProcessor::DOCBOOK || !in_array($element->localName, self::ELEMENTS, true)) {
                continue;
            }
            if (in_array($element->localName, ['eztemplate', 'eztemplateinline'], true)) {
                $definition = $definitions[$element->getAttribute('name')] ?? null;
                if ($definition === null || $element->localName !== $definition['element']) {
                    throw new \UnexpectedValueException('Unknown, disabled or incorrectly placed custom tag.');
                }
                $this->attributes($element, ['name', 'type']);
                if ($element->hasAttribute('type') && $element->getAttribute('type') !== 'tag') {
                    throw new \UnexpectedValueException('Custom styles cannot be generated as custom tags.');
                }
                $this->validateTag($element, $definition['attributes']);
            } elseif ($element->localName === 'ezvalue') {
                $this->attributes($element, ['key']);
                $this->parent($element, ['ezconfig']);
                foreach ($element->childNodes as $child) {
                    if (!$child instanceof \DOMText) {
                        throw new \UnexpectedValueException('Custom tag attribute values must be plain text.');
                    }
                }
            } else {
                $this->attributes($element, []);
                $this->parent($element, ['eztemplate', 'eztemplateinline']);
            }
        }
    }

    /** @param array<string, array<string, mixed>> $attributes */
    private function validateTag(\DOMElement $tag, array $attributes): void
    {
        $children = [];
        foreach ($tag->childNodes as $child) {
            if ($child instanceof \DOMText && trim($child->textContent) === '') {
                continue;
            }
            if (!$child instanceof \DOMElement || $child->namespaceURI !== IbexaRichTextProcessor::DOCBOOK) {
                throw new \UnexpectedValueException('Invalid custom tag content.');
            }
            $children[] = $child;
        }
        if (!in_array(array_map(static fn(\DOMElement $child): string => $child->localName, $children), [['ezcontent'], ['ezcontent', 'ezconfig']], true)) {
            throw new \UnexpectedValueException('Custom tags require ezcontent followed by optional ezconfig.');
        }
        $config = $children[1] ?? null;
        $values = [];
        foreach ($config !== null ? $config->childNodes : [] as $value) {
            if ($value instanceof \DOMText && trim($value->textContent) === '') {
                continue;
            }
            if (!$value instanceof \DOMElement || $value->namespaceURI !== IbexaRichTextProcessor::DOCBOOK || $value->localName !== 'ezvalue') {
                throw new \UnexpectedValueException('Invalid custom tag configuration.');
            }
            $key = $value->getAttribute('key');
            if (!array_key_exists($key, $attributes) || array_key_exists($key, $values)) {
                throw new \UnexpectedValueException('Unknown or duplicate custom tag attribute.');
            }
            $values[$key] = $value->textContent;
        }
        foreach ($attributes as $key => $attribute) {
            if (!array_key_exists($key, $values) && $attribute['default'] !== null) {
                $values[$key] = self::scalar($attribute['default']);
                if ($config === null) {
                    $config = $tag->ownerDocument->createElementNS(IbexaRichTextProcessor::DOCBOOK, 'ezconfig');
                    $tag->appendChild($config);
                }
                $value = $tag->ownerDocument->createElementNS(IbexaRichTextProcessor::DOCBOOK, 'ezvalue');
                $value->setAttribute('key', $key);
                $value->appendChild($tag->ownerDocument->createTextNode($values[$key]));
                $config->appendChild($value);
            }
            $value = $values[$key] ?? null;
            if ($attribute['required'] && ($value === null || trim($value) === '')) {
                throw new \UnexpectedValueException('A required custom tag attribute is missing.');
            }
            if ($value === null || ($value === '' && !$attribute['required'])) {
                continue;
            }
            $valid = match ($attribute['type']) {
                'string' => true,
                'number' => is_numeric($value) && is_finite((float)$value),
                'boolean' => in_array($value, ['true', 'false'], true),
                'choice' => in_array($value, array_map(self::scalar(...), $attribute['choices']), true),
                'link' => (bool)preg_match('~^(https?://|mailto:|tel:|/(?!/)|#)~i', $value),
                default => false,
            };
            if (!$valid) {
                throw new \UnexpectedValueException('Invalid custom tag attribute value.');
            }
        }
    }

    private static function scalar(string|int|float|bool $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }

    /** @param list<string> $allowed */
    private function attributes(\DOMElement $element, array $allowed): void
    {
        foreach ($element->attributes as $attribute) {
            if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
                continue;
            }
            if ($attribute->namespaceURI !== null || !in_array($attribute->name, $allowed, true)) {
                throw new \UnexpectedValueException('Unsupported custom tag XML attribute.');
            }
        }
    }

    /** @param list<string> $allowed */
    private function parent(\DOMElement $element, array $allowed): void
    {
        $parent = $element->parentNode;
        if (!$parent instanceof \DOMElement || $parent->namespaceURI !== IbexaRichTextProcessor::DOCBOOK || !in_array($parent->localName, $allowed, true)) {
            throw new \UnexpectedValueException('Custom tag metadata is outside a custom tag.');
        }
    }
}
