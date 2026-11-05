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
