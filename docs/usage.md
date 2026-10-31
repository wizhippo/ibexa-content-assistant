# AI content assistant for Ibexa 5

## Purpose

Extend the Ibexa 5.0.10 content editor with authoring and schema-aware rewriting,
using Symfony AI 0.15. Editors review a formatted suggestion and explicitly apply
it to the draft. Existing publishing permissions and autosave continue to apply.
No generation endpoint saves or publishes repository content.

## AI Actions editor workflow

This application follows the [Ibexa AI Actions concepts](https://doc.ibexa.co/en/latest/ai/ai_actions/ai_actions_guide/)
with application-owned configuration and a Symfony AI handler. It does not install
or impersonate the commercial `ibexa/connector-ai` API: that package is absent
from this OSS installation and unavailable from the configured Composer repositories.
The supported scope is editor actions only; image alt text and taxonomy suggestions
are excluded.

- Open **Admin > AI actions** to create, edit, delete, enable or disable reusable
  action configurations. Search and filter by type and status.
- Each configuration has a stable identifier, a display name, a developer-defined
  action type and an administrator-defined prompt. Types cover writing,
  continuation, refinement, shortening, expansion and correction. All use the
  existing Symfony AI agent and its configured model/provider.
- **AI actions** beside each allowed editable field opens the action dialog for
  that field. Entire field is the default scope; RichText selections include the
  complete selected model fragment, including formatting and embedded content.
- Choose an enabled configuration, add instructions (required for writing), generate, review the
  formatted preview, then Apply to draft or Discard. Apply remains undoable in
  RichText. Existing normal save/publish controls complete the editorial workflow.
- The endpoint resolves configurations on the server for every request. A deleted
  or disabled action cannot be executed from a stale editor or by supplying its
  raw operation. Configuration prompts and language/field context accompany the
  complete structured document to Symfony AI.

Configurations persist using Ibexa's public `SettingService`, in the
`wizhippo_ibexa_content_assistant/actions` setting, without schema migrations. YAML defaults in
`config/packages/wizhippo_ibexa_content_assistant.yaml (host application)` are used only before the first admin
change; deleting all actions leaves the list empty. The administration page
requires `wizhippo_ibexa_content_assistant/manage`, full authentication, CSRF
protection, and Ibexa `setting/create` and `setting/update` permissions for
writes. Execution continues to require `wizhippo_ibexa_content_assistant/use` and
permission to edit the field.

Administration forms reject stale revisions, and configuration writes use a file
lock in the project's `var` directory. Deployments with multiple application nodes
must share that lock directory or replace the lock store with a distributed store.
Configurations are repository-wide; the existing field allowlist remains in YAML.
User and configuration instructions are separately bounded to 2000 characters.
RichText uses the complete XML document up to 128,000 bytes, including embed-only
content; it is not restricted by the plain text field's 12,000-character limit.
Oversized or invalid model responses are rejected without applying partial content.

## Native RichText pipeline

1. Serialize the chosen content through Ibexa's `BaseRichText.xhtmlify()` helper,
   exactly as the native editor synchronizes its hidden form value. For selected
   content, use CKEditor's selected model fragment and data downcast pipeline.
2. Send the editor XHTML and actual supported heading/table/list capabilities to
   the authenticated application endpoint. Plain text fields use a separate text
   path. Legacy text-segment requests are rejected with a reload message.
3. Convert editor XHTML to Ibexa DocBook through `InputHandlerInterface`, including
   native normalization, and validate using `ibexa.richtext.validator.docbook`.
4. Replace existing embeds, custom tags, and extended nodes with opaque placeholders
   before calling the model. The provider does not receive their configuration,
   content references, passcodes, or other embedded values.
5. Provide the model with an explicit Ibexa DocBook generation contract: namespace,
   section wrapper, supported heading levels, lists, formatting, link rules, and
   HTML-style DocBook tables (`informaltable/thead/tbody/tr/th/td`). Cells contain
   paragraphs. Rewriting can restructure content and create headings and tables.
6. Reject unsupported generated elements/attributes, external entity declarations,
   unsafe links, unsupported editor features, and invalid DocBook. Restore every
   protected object exactly once, unchanged, in its original relative order.
   Existing link destinations must remain unchanged. Validate the restored result
   with the installed Ibexa schema again.
7. For authoring and continuation, combine the original content with the newly
   generated section. For rewriting, the result replaces the chosen content.
8. Convert the validated document through both the native `Html5Edit` converter
   and the native output XHTML converter. The response contains editable XHTML
   and rendered preview HTML derived from the same DocBook document.
9. Upcast the editable XHTML through the actual CKEditor schema before enabling
   Apply. The formatted preview displays native rendered HTML, including embeds,
   in a sandboxed iframe with the current admin/editor styles. Scripts, top-level
   navigation, popups and form submissions are not permitted; controls are
   noninteractive. Public site theme styling may differ from the admin preview.
10. Apply a full-field replacement through CKEditor's data controller with an
    undoable batch, or use native model insertion for a selected range. Ibexa's
    existing `change:data` listener updates its form value and autosave normally.
    Suggestions cannot overwrite a field changed after generation.

Generated XML is not inserted directly into the page or persisted directly to
storage. The AI's claim of validity is never trusted in place of native validation.
Plain text fields continue to use plain text previews and input/change events.

## Actions and editor workflow

- **Write an article**: use the current content as context and append a new article.
- **Continue writing**: append a continuation without repeating the source.
- **Rewrite / Shorten / Expand / Correct**: replace the chosen content, with
  presentation changes when requested and supported by the editor.

Choose the target field, action, and **Entire selected field** or **Selected text
in the RichText editor**. Enter instructions such as “Use section headings and
turn the itinerary into a table.” Generate, inspect the formatted result, then
click **Apply to draft**. A selection preview shows only the selected portion;
content outside that selection remains unchanged. Save or publish through Ibexa.

Generation and validation failures leave the content unchanged. Apply reports
whether the content changed, and native undo restores the previous content.

## Configuration and setup

1. Install locked dependencies with `composer install`.
2. Set `OPENAI_API_KEY` in local/deployment secrets. Select the model through
   `WIZHIPPO_IBEXA_CONTENT_ASSISTANT_MODEL`. The existing supplier formatting command remains a
   separate integration and is not migrated by this feature.
   Set `OPENAI_BASE_URL` to the API base URL including its version path. It defaults
   to `https://api.openai.com/v1` in the checked-in `.env` example. The assistant
   appends `/responses`, using Symfony AI's OpenResponses bridge. A compatible
   provider must support the Responses API; Chat Completions-only endpoints do
   not work with this configuration. Trailing slashes are supported.

   For OpenRouter, override these together in `.env.local` or deployment secrets:

   ```dotenv
   OPENAI_BASE_URL=https://openrouter.ai/api/v1
   OPENAI_API_KEY=your-openrouter-api-key
   WIZHIPPO_IBEXA_CONTENT_ASSISTANT_MODEL=openai/gpt-4.1-mini
   ```

   Provider-prefixed model identifiers are accepted without catalog changes.
   Choose a model supported by the selected provider's Responses API. The endpoint
   override applies to the content assistant; the supplier formatting integration
   retains its own client configuration. Clear the application cache and restart
   the application after changing injected deployment environment variables.
3. Set `WIZHIPPO_IBEXA_CONTENT_ASSISTANT_ENABLED=1` to enable the panel.
4. Configure the explicit field allowlist in
   `config/packages/wizhippo_ibexa_content_assistant.yaml (host application)`. Currently enabled:

   ```yaml
   wizhippo_ibexa_content_assistant:
       fields:
       article: [title, body]
       knowledge_library_category: [title, body]
   ```

5. Assign `wizhippo_ibexa_content_assistant/use` to the intended Ibexa editorial role.
   Existing wildcard administrators already have access. Content read/edit,
   draft, language, and field limitations still apply. Associate/Affiliate agent
   types do not grant this permission.
6. Build the Ibexa assets:

   ```bash
   php bin/console ibexa:encore:compile --frontend-configs-name ibexa
   php bin/console cache:clear
   ```

The assistant has its own `wizhippo-ibexa-content-assistant-js` entry, so failures in unrelated
editor scripts do not prevent its initialization. Hard-refresh an already open
editor after an asset rebuild.

## Controls and limits

- Authenticated requests, CSRF tokens, field allowlist, dedicated policy, and normal
  Ibexa permission checks including field/language limitation targets.
- Ten requests per user per minute, configurable in the content assistant package.
- Plain fields: up to 12,000 source characters. RichText: complete XML up to 128,000 bytes. Each instruction input: up to 2,000 characters.
- Native XML input/output up to 128 KB; complete request up to 256 KB.
- Provider output budget of 8,000 tokens; HTTP deadline of 60 seconds.
- XML is parsed with network access disabled; supplied DTD/entity declarations,
  arbitrary generated elements, scripts, styles, and unsafe link protocols are
  rejected before conversion. Existing protected nodes are restored by the server.
- No persisted conversation history. Application logs contain metadata and failure
  classes, not prompts/results, credentials, or provider exception messages.
  Symfony's development profiler may record AI diagnostics and HTTP traffic.
- These per-request limits are not a monthly spending cap. Durable acceptance
  auditing, token/cost reporting and monthly budgets remain separate work.

## Troubleshooting

### Authentication failures

An OpenAI `invalid_api_key` response (401) means its credentials were rejected.
Replace `OPENAI_API_KEY` in `.env.local` or deployment secrets. Injected container
variables override dotenv files; recreate the app service when injected environment
values change. Clear the cache and retry. Keys must not be committed or pasted into
chat. The assistant shows a specific credential error without raw exception text.

### Models absent from Symfony AI's catalog

The assistant uses Symfony AI's fallback Responses model catalog, accepting the
configured model identifier unchanged (including provider prefixes). This avoids
rejecting models newer than the bundled catalog. It does not verify that the
provider supports that model or that the API account has access to it.

### Multipart responses

Reasoning models may return reasoning and visible text as separate parts. Both
assistant services use Symfony AI's `asText()` accessor to extract visible output
only. Reasoning is never treated as article content or XML.

### Invalid suggestions

Malformed XML, unsupported structure, changed/deleted embed placeholders, changed
link destinations, and schema validation failures are rejected before preview or
application. Refine the instructions or regenerate. No automatic repair request
or additional provider call is made.

## Validation

```bash
php vendor/bin/phpunit --filter 'ContentAssistant|IbexaRichTextProcessor'
node vendor/wizhippo/ibexa-content-assistant/tests/JavaScript/content-assistant.test.cjs
```

Automated tests use mocked model responses. Native integration tests exercise the
installed Ibexa XHTML input handler, RelaxNG/Schematron schema validation, edit
converter, output converter, and DocBook/editor round-trip for headings, lists and
tables. Additional cases cover protected embeds/custom tags, unsafe markup,
entity declarations, original URLs, disabled editor features, permissions, rate
limiting, formatted preview payloads, stale application, and undoable data batches.
Mock HTTP tests verify the configured OpenAI/OpenRouter endpoint, authentication,
provider-prefixed model names, output token options, and trailing slash handling.

Run PHPStan, container/Twig lint, and the Ibexa asset build. Verify the actual UI
with representative articles, embeds, custom tags, formatted selections, native
undo, autosave, and normal publishing. Browser Relay verification of the native
implementation generated headings and an itinerary table, confirmed that both
rendered in the preview and applied as real heading/table structures in CKEditor,
and restored the original content with native undo. Live embed-rich samples and
selection edge cases still warrant editorial review.

Latest verification: 68 PHP tests and 12 JavaScript tests passed; PHPStan, service
container/Twig lint, and the asset build passed. Existing vendor deprecation
warnings remain. The AI Actions refactor was also checked in the shared browser:
configuration management loaded, field launchers opened the dialog, and entire-field
scope was the default. This refactor check did not call the provider or change the
draft. The earlier live formatting test was undone, and the subsequent autosave
was checked against the original editor XHTML with XML canonicalization: the
original draft was restored exactly.

## Future work

See the [roadmap and open decisions](roadmap.md) for field type extensions,
editorial configuration, provider policies, usage reporting, and proposed workflows.

## References

- https://symfony.com/doc/current/ai/bundles/ai-bundle.html
- https://doc.ibexa.co/en/latest/content_management/field_types/field_type_reference/richtextfield/
- https://ckeditor.com/docs/ckeditor5/latest/api/module_engine_controller_datacontroller-DataController.html

## Test ownership

All assistant tests, including native schema/converter and endpoint integration
tests, are in this bundle. The default PHPUnit suite excludes `tests/Integration`;
use `phpunit.integration.xml.dist` with a configured Ibexa host's `KERNEL_CLASS` and
`WIZHIPPO_TEST_BOOTSTRAP` for those tests. See README for exact commands.
