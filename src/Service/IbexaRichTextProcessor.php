<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Service;

use Ibexa\Contracts\FieldTypeRichText\RichText\Converter;
use Ibexa\Contracts\FieldTypeRichText\RichText\InputHandlerInterface;
use Ibexa\Contracts\FieldTypeRichText\RichText\ValidatorInterface;
use Ibexa\FieldTypeRichText\RichText\Converter\Html5Edit;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Wizhippo\IbexaContentAssistant\Exception\InvalidRichTextSuggestion;

final class IbexaRichTextProcessor
{
    public const string DOCBOOK = 'http://docbook.org/ns/docbook';
    public const string XHTML = 'http://ibexa.co/xmlns/dxp/docbook/xhtml';
    public const string XLINK = 'http://www.w3.org/1999/xlink';
    public const string XML = 'http://www.w3.org/XML/1998/namespace';
    private const array ELEMENTS = ['section', 'title', 'para', 'emphasis', 'link', 'itemizedlist', 'orderedlist', 'listitem', 'informaltable', 'thead', 'tbody', 'tr', 'th', 'td', 'blockquote', 'literallayout', 'subscript', 'superscript'];

    /**
     * Generation policy, not a replacement for Ibexa's schema. Empty namespace
     * means an unprefixed attribute (the default namespace never applies to it).
     * Keep this map shared with the model contract so the two cannot drift.
     * @var array<string, array<string, list<string>>>
     */
    private const array ATTRIBUTES = [
        'section' => ['' => ['version']],
        'title' => [self::XHTML => ['level', 'textalign']],
        'para' => [self::XHTML => ['textalign']],
        'emphasis' => ['' => ['role']],
        'link' => [self::XLINK => ['href', 'show', 'title']],
        'orderedlist' => ['' => ['startingnumber', 'numeration']],
        'informaltable' => ['' => ['width', 'border', 'title']],
        'tr' => [self::XHTML => ['width']],
        'th' => ['' => ['colspan', 'rowspan', 'scope', 'abbr', 'valign'], self::XHTML => ['width', 'textalign']],
        'td' => ['' => ['colspan', 'rowspan', 'scope', 'valign'], self::XHTML => ['width', 'textalign']],
        'literallayout' => ['' => ['class']],
    ];
    private const array ATTRIBUTE_VALUES = [
        'emphasis' => ['role' => ['strong', 'underlined', 'strikedthrough']],
        'literallayout' => ['class' => ['normal', 'monospaced']],
        // The schema also permits XLink embed/other, which the editor does not generate.
        'link' => ['show' => ['none', 'new', 'replace']],
    ];

    public function __construct(
        private readonly InputHandlerInterface                                                       $inputHandler,
        #[Autowire(service: 'ibexa.richtext.validator.docbook')] private readonly ValidatorInterface $validator,
        #[Autowire(service: Html5Edit::class)] private readonly Converter                            $editConverter,
        #[Autowire(service: 'ibexa.richtext.converter.output.xhtml5')] private readonly Converter    $outputConverter,
        #[Autowire(param: 'wizhippo_ibexa_content_assistant.max_xml_bytes')] private readonly int    $maxXmlBytes = 4194304,
        private readonly ?RichTextCustomTagRegistry                                                  $customTags = null,
        private readonly ?LoggerInterface                                                            $logger = null,
    )
    {
    }

    public function read(string $xml): \DOMDocument
    {
        $this->guardXml($xml);
        try {
            $document = $this->inputHandler->fromString($xml);
            if ($this->validator->validateDocument($document) !== []) {
                throw new \InvalidArgumentException('The current RichText could not be validated.');
            }
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('The current RichText could not be processed. Reload the editor and retry.', previous: $exception);
        }

        return $document;
    }

    /**
     * Keep original extended nodes outside the model's control. The tokens contain
     * no embed IDs, custom-tag values, credentials, or executable markup.
     * @return array{xml: string, protected: array<string, array{node: \DOMElement, block: bool}>}
     */
    public function protect(\DOMDocument $source): array
    {
        $document = clone $source;
        $protected = [];
        $candidates = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->namespaceURI === self::DOCBOOK && in_array($element->localName, self::ELEMENTS, true)) {
                continue;
            }
            $candidate = $element;
            $inline = in_array($element->localName, ['ezembedinline', 'eztemplateinline', 'anchor'], true);
            if (!$inline && !in_array($element->localName, ['ezembed', 'eztemplate'], true)) {
                // Metadata cannot be replaced with text inside a row or table.
                // Preserve its containing block as one opaque object instead.
                while ($candidate->parentNode instanceof \DOMElement
                    && !in_array($candidate->parentNode->localName, ['section', 'blockquote', 'listitem', 'td', 'th'], true)) {
                    $candidate = $candidate->parentNode;
                }
            }
            $candidates[] = ['node' => $candidate, 'block' => !$inline];
        }
        $chosen = [];
        foreach ($candidates as $candidate) {
            $node = $candidate['node'];
            foreach ($candidates as $other) {
                for ($parent = $node->parentNode; $parent instanceof \DOMElement; $parent = $parent->parentNode) {
                    if ($parent->isSameNode($other['node'])) {
                        continue 3;
                    }
                }
            }
            $chosen[spl_object_id($node)] = $candidate;
        }
        // Use document order, regardless of which descendant selected a block.
        $ordered = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($chosen as $candidate) {
                if ($element->isSameNode($candidate['node'])) {
                    $ordered[] = $candidate;
                    break;
                }
            }
        }
        foreach ($ordered as $candidate) {
            $element = $candidate['node'];
            $token = 'AI_KEEP_' . bin2hex(random_bytes(12));
            $copy = $element->cloneNode(true);
            if (!$copy instanceof \DOMElement) {
                throw new \UnexpectedValueException('A protected RichText element could not be copied.');
            }
            $protected[$token] = ['node' => $copy, 'block' => $candidate['block']];
            $replacement = $document->createTextNode($token);
            if ($candidate['block']) {
                $paragraph = $document->createElementNS(self::DOCBOOK, 'para');
                $paragraph->appendChild($replacement);
                $replacement = $paragraph;
            }
            $element->parentNode->replaceChild($replacement, $element);
        }

        return ['xml' => (string)$document->saveXML(), 'protected' => $protected];
    }

    /**
     * @param array<string, array{node: \DOMElement, block: bool}> $protected
     * @param array<string, mixed> $capabilities
     */
    public function generated(string $xml, array $protected, \DOMDocument $source, bool $append, array $capabilities): \DOMDocument
    {
        try {
            $document = $this->parse($xml);
            $this->assertGeneratedElements($document, $capabilities);
            $this->assertSchema($document, 'generated');
            $expected = $append ? [] : $protected;
            $this->restore($document, $expected);
            $this->checkLinks($document, $source, !$append);
            $this->assertSchema($document, 'restored');
            if ($append) {
                $combined = clone $source;
                foreach (iterator_to_array($document->documentElement->childNodes) as $child) {
                    $combined->documentElement->appendChild($combined->importNode($child, true));
                }
                $document = $combined;
            }

            return $document;
        } catch (InvalidRichTextSuggestion $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new InvalidRichTextSuggestion('The suggestion could not be validated as Ibexa RichText. Your field has not changed. Please regenerate.', previous: $exception);
        }
    }

    /** @return array{editXhtml: string, previewHtml: string} */
    public function render(\DOMDocument $document): array
    {
        return [
            'editXhtml' => (string)$this->editConverter->convert(clone $document)->saveXML(),
            'previewHtml' => (string)$this->outputConverter->convert(clone $document)->saveHTML(),
        ];
    }

    /**
     * The generation contract is a subset of the installed, validated schema.
     * @param array<string, mixed> $capabilities
     * @return array<string, mixed>
     */
    public function contract(array $capabilities): array
    {
        $tags = $this->customTags?->definitions($capabilities) ?? [];

        return [
            'format' => 'Ibexa 5 RichText DocBook XML, never HTML or Markdown',
            'root' => '<section xmlns="' . self::DOCBOOK . '" xmlns:ezxhtml="' . self::XHTML . '" xmlns:xlink="' . self::XLINK . '" version="5.0-variant ezpublish-1.0">...</section>',
            'elements' => array_merge(self::ELEMENTS, $tags === [] ? [] : RichTextCustomTagRegistry::ELEMENTS),
            'attributes' => $this->attributeContract(),
            'attribute_values' => self::ATTRIBUTE_VALUES,
            'attribute_rules' => 'Only attributes listed for each element are allowed. Map keys are namespace URIs; an empty key means NO namespace. Unprefixed attributes have no namespace, even with a default element namespace. Use xml:id, never id; ezxhtml:level and ezxhtml:textalign, never level or textalign; xlink:href, xlink:show and xlink:title only on link. Use the displayed prefixes and exact namespace URIs; the installed Ibexa Schematron requires the literal xlink:href prefix. xlink:title may be empty. attribute_values further restricts values; all other values and document structure must pass the installed Ibexa schema. Custom tag XML permits only unprefixed name and optional type=tag on eztemplate/eztemplateinline, key on ezvalue, and no attributes on ezcontent/ezconfig; their configuration values follow custom_tags.',
            'custom_tags' => $tags,
            'custom_tag_format' => '<eztemplate name="configured_name"><ezcontent><para>Block content</para></ezcontent><ezconfig><ezvalue key="configured_attribute">Plain text value</ezvalue></ezconfig></eztemplate>',
            'custom_tag_rules' => 'Only use names in custom_tags. Use the specified element: eztemplate for blocks, eztemplateinline inside paragraphs for inline tags. Always include ezcontent first, then optional ezconfig with plain-text ezvalue children keyed by configured attribute names. Inline ezcontent contains inline text, never paragraphs. Supply required attributes; use only configured choices and valid types. Omitted attributes receive configured defaults. Do not invent facts, URLs, content IDs or configuration values. Use labels and descriptions to decide when a tag is appropriate.',
            'heading_levels' => $capabilities['headingLevels'],
            'tables' => $capabilities['tables'],
            'lists' => $capabilities['lists'],
            'literal_layout_class' => ['normal', 'monospaced'],
            'namespaces' => ['default' => self::DOCBOOK, 'ezxhtml' => self::XHTML, 'xlink' => self::XLINK, 'xml' => self::XML],
            'link_rules' => 'For rewrites, preserve every original hyperlink with each required_links URL appearing as a link xlink:href exactly occurrences times, including duplicate links. Copy each URL verbatim, including tracking URLs, redirects, query parameters and percent encoding. Never shorten, normalize, decode, or replace a URL with its redirect destination. XML-escape attribute values so the decoded xlink:href value remains identical (for example & becomes &amp;). Links inside AI_KEEP_ blocks are restored automatically. For author and continue, required_links is empty because existing content and links are retained automatically; do not repeat them.',
            'examples' => [
                '<title ezxhtml:level="2" ezxhtml:textalign="center">Section heading</title>',
                '<para><literallayout class="normal">Line one&#10;Line two</literallayout></para>',
                '<para>Text with <emphasis role="strong">bold</emphasis> and <emphasis>italic</emphasis>.</para>',
                '<itemizedlist><listitem><para>List item</para></listitem></itemizedlist>',
                '<informaltable border="1" title="Schedule"><thead><tr><th scope="col" abbr="Name" valign="middle" ezxhtml:width="50%"><para>Column</para></th></tr></thead><tbody><tr><td><para>Value</para></td></tr></tbody></informaltable>',
                '<para xml:id="supplier">Supplier information</para>',
                '<para><link xlink:href="https://example.com" xlink:show="none" xlink:title="">Link label</link></para>',
                '<para><link xlink:href="https://example.com/details" xlink:title="Supplier details">Details</link></para>',
            ],
            'rules' => 'Copy the namespace URIs from namespaces exactly; never change them or insert additional path segments. Table cells and list items contain para elements. Follow link_rules. Preserve all AI_KEEP_ tokens exactly once, in order. A block token stays alone in its para; an inline token stays inline. Never create embeds, unlisted custom tags, scripts, custom styles, DOCTYPE, entities, or new internal content references. You may restructure prose into headings, lists and tables according to the instructions and supported capabilities.',
        ];
    }

    /** @return array<string, array<string, list<string>>> */
    private function attributeContract(): array
    {
        $attributes = self::ATTRIBUTES;
        foreach (self::ELEMENTS as $element) {
            $attributes[$element][self::XML] = ['id'];
        }

        return $attributes;
    }

    private function assertSchema(\DOMDocument $document, string $stage): void
    {
        $errors = $this->validator->validateDocument($document);
        if ($errors !== []) {
            // Validator messages may quote prose or values. Only log stage/count.
            $this->logger?->warning('RichText generation rejected: schema validation failed.', ['stage' => $stage, 'error_count' => count($errors)]);
            throw new \UnexpectedValueException('The ' . $stage . ' suggestion does not conform to the Ibexa RichText schema.');
        }
    }

    private function parse(string $xml): \DOMDocument
    {
        $this->guardXml($xml);
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->documentElement?->localName !== 'section') {
                throw new \InvalidArgumentException('A complete RichText section is required.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function guardXml(string $xml): void
    {
        if (strlen($xml) > $this->maxXmlBytes || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml)) {
            throw new \InvalidArgumentException('RichText XML is too large or contains a prohibited document declaration.');
        }
    }

    /** @param array<string, mixed> $capabilities */
    private function assertGeneratedElements(\DOMDocument $document, array $capabilities): void
    {
        $tags = $this->customTags?->definitions($capabilities) ?? [];
        $this->customTags?->validate($document, $tags);
        $attributes = $this->attributeContract();
        foreach ($document->getElementsByTagName('*') as $element) {
            $customTagElement = $tags !== [] && in_array($element->localName, RichTextCustomTagRegistry::ELEMENTS, true);
            if ($element->namespaceURI !== self::DOCBOOK || (!in_array($element->localName, self::ELEMENTS, true) && !$customTagElement)) {
                throw new \UnexpectedValueException('Unsupported generated element.');
            }
            if ($customTagElement) {
                continue; // Custom tag attributes and structure were checked by the registry.
            }
            foreach ($element->attributes as $attribute) {
                if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
                    continue;
                }
                $namespace = $attribute->namespaceURI ?? '';
                $allowed = $attributes[$element->localName][$namespace] ?? [];
                if (!in_array($attribute->localName, $allowed, true)) {
                    // Unknown names/URIs are model-controlled and can contain secrets.
                    // Report only known vocabulary, never raw XML or attribute values.
                    $knownNames = ['id'];
                    foreach ($attributes as $namespaces) {
                        foreach ($namespaces as $names) {
                            $knownNames = array_merge($knownNames, $names);
                        }
                    }
                    $context = [
                        'element' => $element->localName,
                        'attribute' => in_array($attribute->localName, $knownNames, true) ? $attribute->localName : '[unrecognized]',
                        'namespace' => in_array($namespace, ['', self::XML, self::XHTML, self::XLINK, self::DOCBOOK], true) ? $namespace : '[unrecognized]',
                        'line' => $element->getLineNo(),
                    ];
                    $this->logger?->warning('RichText generation rejected: unsupported attribute.', $context);
                    throw new \UnexpectedValueException(sprintf('Unsupported generated attribute (element=%s, namespace=%s, attribute=%s, line=%d).', ...array_values($context)));
                }
                $values = self::ATTRIBUTE_VALUES[$element->localName][$attribute->localName] ?? null;
                if ($values !== null && !in_array($attribute->value, $values, true)) {
                    $this->logger?->warning('RichText generation rejected: prohibited attribute value.', ['element' => $element->localName, 'attribute' => $attribute->localName, 'line' => $element->getLineNo()]);
                    throw new \UnexpectedValueException('Prohibited generated attribute value.');
                }
            }
            if ($element->localName === 'title' && !in_array((int)$element->getAttributeNS(self::XHTML, 'level'), $capabilities['headingLevels'], true)) {
                throw new \UnexpectedValueException('The editor does not support this heading level.');
            }
            if ((!$capabilities['tables'] && $element->localName === 'informaltable')
                || (!$capabilities['lists'] && in_array($element->localName, ['itemizedlist', 'orderedlist'], true))) {
                throw new \UnexpectedValueException('The editor does not support the requested structure.');
            }
        }
    }

    /** @param array<string, array{node: \DOMElement, block: bool}> $protected */
    private function restore(\DOMDocument $document, array $protected): void
    {
        preg_match_all('/AI_KEEP_[a-f0-9]{24}/', $document->textContent, $matches);
        if ($matches[0] !== array_keys($protected)) {
            throw new \UnexpectedValueException('Embedded content placeholders were changed, removed, duplicated or reordered.');
        }
        $xpath = new \DOMXPath($document);
        foreach ($protected as $token => $item) {
            $textNodes = $xpath->query('//text()[contains(., "' . $token . '")]');
            if ($textNodes->length !== 1) {
                throw new \UnexpectedValueException('Embedded content placeholder is invalid.');
            }
            $text = $textNodes->item(0);
            $original = $document->importNode($item['node'], true);
            if ($item['block']) {
                $parent = $text->parentNode;
                if (!$parent instanceof \DOMElement || $parent->localName !== 'para' || trim($parent->textContent) !== $token || $parent->getElementsByTagName('*')->length !== 0) {
                    throw new \UnexpectedValueException('An embedded block was moved inside other content.');
                }
                $parent->parentNode->replaceChild($original, $parent);
            } else {
                [$before, $after] = explode($token, $text->nodeValue, 2);
                $text->parentNode->insertBefore($document->createTextNode($before), $text);
                $text->parentNode->insertBefore($original, $text);
                $text->parentNode->replaceChild($document->createTextNode($after), $text);
            }
        }
    }

    /**
     * Only inspect the protected XML sent to the model, never opaque source blocks.
     * @return list<array{url: string, occurrences: int}>
     */
    public function requiredLinks(string $protectedXml): array
    {
        $required = [];
        foreach (array_count_values($this->links($this->parse($protectedXml))) as $url => $count) {
            $required[] = ['url' => (string)$url, 'occurrences' => $count];
        }

        return $required;
    }

    /** @return list<string> */
    private function links(\DOMDocument $document): array
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('db', self::DOCBOOK);
        $xpath->registerNamespace('xlink', self::XLINK);

        return array_map(static fn(\DOMAttr $attribute): string => $attribute->value, iterator_to_array($xpath->query('//db:link/@xlink:href')));
    }

    private function checkLinks(\DOMDocument $document, \DOMDocument $source, bool $requireOriginals): void
    {
        $originals = $this->links($source);
        $generated = $this->links($document);
        foreach ($generated as $url) {
            if (!preg_match('~^(https?://|mailto:|tel:|#)~i', $url)
                && !(in_array($url, $originals, true) && preg_match('~^ez(?:content|location)://\d+$~D', $url))) {
                throw new \UnexpectedValueException('Unsupported link URL.');
            }
        }
        if ($requireOriginals && array_diff_assoc(array_count_values($originals), array_count_values($generated)) !== []) {
            throw new InvalidRichTextSuggestion('The suggestion removed or changed an existing hyperlink. Every original link, including duplicate and tracking links, must be preserved. Your field has not changed. Please regenerate.');
        }
    }
}
