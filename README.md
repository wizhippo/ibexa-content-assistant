# Ibexa Content Assistant

Schema-aware AI editor actions for **Ibexa 5**, powered by **Symfony AI**.

## Features

- Reusable action configurations managed in **Admin → AI actions**.
- Write, continue, refine, shorten, expand, correct spelling, and adjust tone.
- AI actions alongside enabled fields in the content editor.
- Process the entire unsaved RichText document or a selection,
  including headings, tables, lists, links, embedded content, and custom blocks.
- Schema validation, formatted previews, preservation of embedded content, and undo.
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
    fields:
        article: [title, body]
```

The bundle starts disabled with no allowed fields. Set these variables in
`.env.local` or deployment secrets:

```dotenv
WIZHIPPO_IBEXA_CONTENT_ASSISTANT_ENABLED=1
WIZHIPPO_IBEXA_CONTENT_ASSISTANT_MODEL=gpt-4.1-mini
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=your-api-key
```

OpenRouter example:

```dotenv
OPENAI_BASE_URL=https://openrouter.ai/api/v1
OPENAI_API_KEY=your-openrouter-key
WIZHIPPO_IBEXA_CONTENT_ASSISTANT_MODEL=openai/gpt-4.1-mini
```

The bridge appends `/responses`; include the API version path in the base URL.

Build the Ibexa assets and clear the application cache:

```sh
php bin/console cache:clear
php bin/console ibexa:encore:compile --frontend-configs-name ibexa
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
node tests/JavaScript/content-assistant.test.cjs
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

See [usage and implementation details](docs/usage.md) for the processing pipeline,
limits, troubleshooting, and data-sharing behavior.
See the [roadmap and open decisions](docs/roadmap.md) for proposed field type
support and future work.

## Authors

- [Douglas Hammond (wizhippo)](https://github.com/wizhippo) — author and maintainer.
- LLM — AI-assisted implementation, refactoring, and tests.

## License

GNU General Public License **version 2 only** (`GPL-2.0-only`). See [LICENSE](LICENSE).
