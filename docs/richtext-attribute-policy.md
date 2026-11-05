# RichText generation attribute policy

The assistant accepts a deliberate subset of Ibexa RichText. The policy checks
an attribute's owning DocBook element, namespace URI and local name together.
An unprefixed attribute has **no namespace**, even inside a default DocBook
namespace. Prefix spelling alone cannot establish namespace identity.

The previous policy compared only local names against one flat list. It rejected
valid native attributes such as `xlink:title`, even when empty, while admitting
names such as `href`, `role` or `level` in unrelated contexts. Ibexa's validator
sometimes rejected these later, but schema conformance alone is insufficient:
the installed schema permits linking attributes on non-link elements, table
scripts and arbitrary styles. The assistant must independently restrict those.

## Installed schema and converter audit

Audited against `ibexa/fieldtype-richtext` 5.0.10 as installed in the host:

- `src/bundle/Resources/richtext/schemas/docbook/ezpublish.rng`
- Its included `docbook.rng` and `docbook.iso.sch.xsl`
- `stylesheets/xhtml5/edit/docbook.xsl` (editor input)
- `stylesheets/docbook/xhtml5/{edit,output}/core.xsl` (editor and output)
- `RichText/Converter/Link.php` (internal link resolution)

Paths above are relative to the package's RichText resources unless indicated.
The configured Ibexa validator remains the authority for attribute types,
enumerations, document structure and schema extensions. No schema is copied
into the assistant and no schema errors are suppressed.

| Context | Namespace | Attributes accepted for generation |
| --- | --- | --- |
| All supported standard elements | XML (`http://www.w3.org/XML/1998/namespace`) | `id` |
| `section` | none | `version` |
| `title` | Ibexa XHTML (`http://ibexa.co/xmlns/dxp/docbook/xhtml`) | `level`, `textalign` |
| `para` | Ibexa XHTML | `textalign` |
| `emphasis` | none | `role`: `strong`, `underlined`, `strikedthrough` |
| `link` | XLink (`http://www.w3.org/1999/xlink`) | `href`, `title` (including empty), `show`: `none`, `new`, `replace` |
| `orderedlist` | none | `startingnumber`, `numeration` |
| `informaltable` | none | `width`, `border`, `title` |
| `tr` | Ibexa XHTML | `width` |
| `th` | none | `colspan`, `rowspan`, `scope`, `abbr`, `valign` |
| `td` | none | `colspan`, `rowspan`, `scope`, `valign` |
| `th`, `td` | Ibexa XHTML | `width`, `textalign` |
| `literallayout` | none | `class`: `normal`, `monospaced` |

Newly accepted native attributes are `link/@xlink:title`,
`para|title|th|td/@ezxhtml:textalign`, `th|td/@valign`, `th/@abbr`, and
`informaltable/@title`. The input converter emits link titles, alignment, cell
vertical alignment and header abbreviations; edit/output converters render the
corresponding native formatting. These are structured formatting attributes,
not permission to generate raw CSS. `xml:id`, table widths, spans and list
attributes were already accepted by local name; they now require their proper
namespace and owning element.

The schema supports more than the converters preserve. In particular, cell
`ezxhtml:textalign`, row `ezxhtml:width`, and ordered-list `startingnumber` can
validate without surviving the installed editor converter unchanged. Acceptance
does not promise lossless editor round trips for all schema attributes. Paragraph
alignment within cells is rendered by the converters. The regression tests check
schema acceptance and the attributes the native converters actually round trip.

The URI policy accepts alternate prefixes, but the installed Schematron checks
link destinations using `name()='xlink:href'`. Models are therefore instructed
to use the displayed canonical prefixes. The assistant does not bypass this
upstream schema restriction. An alternate prefix for `xlink:title` still works
when `xlink:href` uses the canonical prefix.

## Intentional exclusions

- Event handlers, raw `style`, arbitrary `class`/`ezxhtml:class`, custom styles,
  and arbitrary `role` values. Literal-layout classes and the three native
  emphasis roles above are the only exceptions.
- `xml:base`, which can change destination interpretation; common DocBook
  effectivity/revision/cross-reference metadata, `xml:lang` and `dir`.
- Links on elements other than `link`; `linkend`, `endterm`, `xlink:role`,
  `xlink:arcrole`, `xlink:actuate`, `xlink:type`, and `xlink:siteaccess` routing
  metadata. `xlink:show=embed|other` is also excluded. Existing internal URLs
  remain usable only under the existing source-link checks; new internal
  references cannot be generated.
- Legacy table attributes such as `summary`, `frame`, `rules`, `cellpadding`,
  `cellspacing`, `align`, `char`, `charoff`, `axis`, and `headers`, plus list
  `spacing`, `mark`, `override`, `inheritnum`, and `continuation`. They are
  schema-valid in their respective contexts but outside the supported native
  generation contract; many are ignored by the installed editor converters.
- Additional schema elements such as `table`/`caption`, `tfoot`, `col`/`colgroup`,
  `anchor`, attribution and program listings, as well as generated embeds and
  extension elements. Existing extended nodes remain opaque protected content.
- Unconfigured or editor-disabled custom tags. Configured tags continue through
  the registry's separate strict namespace, structure, type, choice and default
  validation. The standard attribute policy does not expand their XML attributes.

Headings, tables and lists still require editor capabilities. Protected-content
restoration, validation before and after restoration, exact hyperlink counts and
destinations, and safe URL schemes remain enforced.

## Contract and diagnostics

The `attributes` model contract is built from the same map used for enforcement;
its keys are exact namespace URIs (empty string means no namespace).
`attribute_values` carries additional generation-only value restrictions.
Examples are tested through both the policy and the installed validator.

The processor logs distinct warnings for unsupported attributes, prohibited
attribute values, and schema failures. Attribute diagnostics include only known
element/attribute names, a known namespace URI or `[unrecognized]`, and the XML
line number. Unknown names and namespaces are redacted because they too can
contain secrets. Schema diagnostics contain only the validation stage
(`generated`/`restored`) and error count. No XML, source text, generated prose,
attribute/link values, validator error messages, or exception objects are logged.
The user-facing failure remains a safe regeneration message.

## Format precedence and bounded repair

The required `response_format`, schema, security and preservation rules take
precedence over configured action templates and additional editorial instructions.
A template asking for Markdown is interpreted as a presentation request and must
still produce DocBook for a RichText field. Action authors should remove requests
for a specific serialization format: for example, replace “Output Markdown” in
“Restructure Supplier Info” with “Use the field's required output format; preserve
all source facts, contact hyperlinks and exact link destinations.” Saved action
settings are not rewritten by a bundle update.

A rejected generated document gets **one** repair retry (at most two model calls
per suggestion). The retry starts from the same protected source, schema, editor
capabilities and required links. It adds diagnostic `repair_feedback` listing
unsupported element names and missing/excess required-link counts together, even
when element validation prevented the normal link check from running. Missing
links include only URLs already disclosed in the editable source, never URLs
inside opaque protected nodes. `occurrences` is the total required count;
`missing_occurrences` or `excess_occurrences` describes the discrepancy.

Unsupported names are deduplicated, limited to 32 entries and 128 UTF-8 bytes each.
Foreign namespace values are redacted. Malformed XML is identified without
forwarding parser messages. Rejected XML/prose is not resent and feedback is
never logged. Each attempt uses the existing agent processors and context/output
budgets; repair feedback can itself exceed the available context budget, in which
case no second provider request is made. Provider failures are not repair retries.

Both attempts must independently pass the complete existing validation pipeline.
Invalid elements are never whitelisted, stripped, or silently replaced. The
model is instructed to use native `literallayout` markup with newline text for
line breaks, retain exact supplied `AI_KEEP_` text tokens, and never invent tags,
facts, contact details or URLs. After a second validation failure, no preview is
returned and the field stays unchanged. Schema/link checks enforce structural
and URL preservation rules; they cannot prove the factual accuracy of every
sentence produced by a model.

Additional safe warning categories are `unsupported_element`,
`missing_required_links`, and `required_link_count_mismatch` (for surplus copies
of an existing link). Unknown element names and namespaces are redacted in logs;
link warnings contain occurrence counts only. URLs, generated prose, source
content, opaque nodes and credentials are never included in these warnings.
