# Roadmap and open decisions

This document tracks proposed work and decisions that need agreement before
implementation. Items have no committed delivery date or priority. See
[usage](usage.md) for current behavior and limits.

## Field type support

The assistant currently supports these field types when explicitly enabled in
the host application's `fields` configuration:

| Field type | Current behavior |
| --- | --- |
| `ibexa_string` | Plain text generation and rewriting |
| `ibexa_text` | Plain text generation and rewriting |
| `ibexa_richtext` | Schema-aware generation and rewriting of the entire field or a selection |

Other field types are excluded by the server-side field access check. Adding a
field to the configuration does not add support for its type.

Proposed extensions:

- **Image alt text:** decide which image field types and metadata properties to
  support, how images reach the provider, and how editors review the suggested text.
- **Taxonomy suggestions:** decide which taxonomy integrations to support and
  whether suggestions select existing terms, propose new terms, or both.
- **Custom field types:** define an extension mechanism for reading editor values,
  preparing provider input, validating suggestions, and applying changes.

Before adding a type, define its editable properties and supported actions,
provider input and privacy requirements, field-level permission checks, native
validation, preview, and editor application behavior. Tests should cover invalid
responses, unavailable permissions, and changes made while generation is pending.

Supporting additional content types or fields that already use a supported field
type is a host configuration decision. Hosts must choose the fields and editorial
roles permitted to use the assistant.

## Editorial configuration

- Define brand guidelines, spelling conventions, and language requirements.
- Decide whether translation should become a dedicated action and how source and
  target languages are selected and reviewed.
- Decide whether hosts need shared prompt presets beyond the existing configurable
  action prompts and YAML defaults.

## Provider and data policies

- Choose approved providers, data residency requirements, and permitted source material.
- Define rules for sending images or other additional data before introducing
  multimodal actions or uploads.

## Usage, budgets, and auditing

Existing request limits do not provide monthly spending controls. Proposed work:

- Token and cost reporting, with a decision on provider-reported usage and pricing.
- Monthly budgets and behavior when a budget is reached.
- Durable records of suggestion acceptance, including retention, access, and
  whether records may contain source or generated content.

## Additional workflows

Uploads, external research, retrieval across repository content, and bulk actions
are outside the current scope. Each needs a separate proposal covering permissions,
data sharing, limits, and editorial review before implementation.

Automatic publishing is not provided. Any proposal to add it requires an explicit
decision about approval and publishing permissions.
