# Ibexa Content Assistant

Schema-aware AI editor actions for **Ibexa 5**, powered by **Symfony AI**.

## Features

- Reusable action configurations managed in **Admin → AI actions**.
- Write, continue, refine, shorten, expand, correct spelling, and adjust tone.
- AI actions in the RichText editor toolbar and beside enabled plain text fields.
- Process the entire unsaved RichText document or a selection,
  including headings, tables, lists, links, embedded content, and custom blocks.
- Schema validation, formatted previews, preservation of embedded content, and undo.
- Generate enabled custom tags using their configured attributes, defaults and choices.
- Configurable provider and model, including OpenRouter-compatible Responses endpoints.

Editors review suggestions before applying them to a draft, then save or publish
through Ibexa.

## Requirements

PHP 8.4+, Ibexa 5, Symfony 7.4, Symfony AI 0.15, and Node.js for building assets.
The provider must support the Responses API.

## Installation

Add a Composer VCS repository for
`https://github.com/wizhippo/ibexa-content-assistant.git` and require
`wizhippo/ibexa-content-assistant:dev-main` (or a published release tag).

Register the bundles in `config/bundles.php`:

```php
Symfony\AI\AiBundle\AiBundle::class => ['all' => true],
Wizhippo\IbexaContentAssistant\WizhippoIbexaContentAssistantBundle::class => ['all' => true],
```

Import the routes in `config/routes/wizhippo_ibexa_content_assistant.yaml`:

```yaml
wizhippo_ibexa_content_assistant:
    resource: '@WizhippoIbexaContentAssistantBundle/config/routes.yaml'
```

Configure allowed content in `config/packages/wizhippo_ibexa_content_assistant.yaml`:

```yaml
wizhippo_ibexa_content_assistant:
    enabled: '%env(bool:WIZHIPPO_IBEXA_CONTENT_ASSISTANT_ENABLED)%'
    context_window: 1050000
    max_output_tokens: 128000
    fields:
        article: [title, body]
```

The bundle starts disabled with no allowed fields. Set these variables in
`.env.local` or deployment secrets:

```dotenv
WIZHIPPO_IBEXA_CONTENT_ASSISTANT_ENABLED=1
WIZHIPPO_IBEXA_CONTENT_ASSISTANT_MODEL=gpt-6.1-terra
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=your-api-key
```

OpenRouter example:

```dotenv
OPENAI_BASE_URL=https://openrouter.ai/api/v1
OPENAI_API_KEY=your-openrouter-key
WIZHIPPO_IBEXA_CONTENT_ASSISTANT_MODEL=openai/gpt-6.1-terra
```

The default model is `gpt-6.1-terra`, with a configurable 1,050,000-token context
budget and 128,000-token output budget. When choosing another model, update both
budgets to match it. Input is conservatively estimated from UTF-8 bytes because
the fallback catalog has no tokenizer; see [budget configuration](docs/usage.md#configuration-and-setup).

The bridge appends `/responses`; include the API version path in the base URL.

Build the Ibexa assets and clear the application cache:

```sh
php bin/console cache:clear
php bin/console ibexa:encore:compile --frontend-configs-name ibexa
php bin/console ibexa:encore:compile --frontend-configs-name richtext
```

## Permissions and persistence

Assign these Ibexa policies to the appropriate roles:

- `wizhippo_ibexa_content_assistant/use`: generate suggestions. Users must also
  be authenticated and have permission to edit the selected draft field.
- `wizhippo_ibexa_content_assistant/manage`: manage action configurations under
  **Admin → AI actions**. Saving also requires `setting/create` and `setting/update`.

Wildcard administrators already have access.

Action configurations are stored through Ibexa's `SettingService`; no schema
migration is needed. YAML `actions` defaults apply until an administrator first
saves configurations. Multiple application nodes must share the lock directory
in `var` or use a distributed lock store.

## Development and tests

From a standalone bundle checkout:

```sh
composer install
vendor/bin/phpunit
node --test tests/JavaScript/*.test.cjs
```

The default suite runs without an application kernel. Integration tests require
an Ibexa application with the bundle enabled. Run them from the application root:

```sh
KERNEL_CLASS='App\Kernel' \
WIZHIPPO_TEST_BOOTSTRAP="$PWD/tests/bootstrap.php" \
vendor/bin/phpunit -c vendor/wizhippo/ibexa-content-assistant/phpunit.integration.xml.dist
```

Adjust the kernel and bootstrap paths for your application. Provider requests in
automated tests are mocked.

RichText output rules override action-template format requests. A failed suggestion
gets one validation-guided repair retry; both attempts must preserve required
links and protected content and pass all validation checks. See the
[format precedence and repair policy](docs/richtext-attribute-policy.md#format-precedence-and-bounded-repair).

See [usage and implementation details](docs/usage.md) for the processing pipeline,
limits, troubleshooting, and data-sharing behavior.
See the [roadmap and open decisions](docs/roadmap.md) for proposed field type
support and future work.

## Authors

- [Douglas Hammond (wizhippo)](https://github.com/wizhippo) — author and maintainer.
- LLM — AI-assisted implementation, refactoring, and tests.

## License

GNU General Public License **version 2 only** (`GPL-2.0-only`). See [LICENSE](LICENSE).
