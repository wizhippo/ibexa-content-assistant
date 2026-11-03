// Ibexa emits this non-bubbling event after constructing its CKEditor instance.
const editors = new WeakMap();
document.addEventListener(
  'ibexa-ckeditor:instance-ready',
  (event) => {
    const field = event.target.closest('.ibexa-field-edit');
    if (field) editors.set(field, event.detail.editor);
  },
  true,
);

const escapeAttribute = (value) =>
  String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('"', '&quot;')
    .replaceAll('<', '&lt;');
const previewDocument = (html) => {
  const styles = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
    .map(
      (link) => `<link rel="stylesheet" href="${escapeAttribute(link.href)}">`,
    )
    .join('');
  return `<!doctype html><html><head><meta charset="utf-8"><base href="${escapeAttribute(window.location.origin)}/">${styles}<style>
    body{padding:20px;margin:0;background:#fff;color:#171b20;font-family:Arial,sans-serif;overflow-wrap:anywhere}
    .ck-content{min-height:0}table{border-collapse:collapse;width:100%;margin:1em 0}th,td{border:1px solid #ccc;padding:.6em;vertical-align:top}th{font-weight:bold;background:#f3f4f5}
    img,video{max-width:100%;height:auto}h1,h2,h3,h4,h5,h6{margin:1em 0 .5em}p{margin:0 0 1em}a,button,input,select,textarea{pointer-events:none}
  </style></head><body><div class="ibexa-richtext ezrichtext-field ck-content">${html}</div></body></html>`;
};

const initialise = () => {
  document.querySelectorAll('[data-content-assistant]').forEach((panel) => {
    if (panel.dataset.initialised) return;
    panel.dataset.initialised = 'true';
    const find = (name) => panel.querySelector(`[data-assistant-${name}]`);
    const status = find('status');
    const preview = find('preview');
    const generate = find('generate');
    let pending = null;
    let revision = 0;

    const discard = () => {
      revision += 1;
      pending = null;
      preview.hidden = true;
      find('source').value = '';
      find('result').value = '';
      find('formatted').srcdoc = '';
    };

    const snapshot = () => {
      const identifier = find('field').value;
      const input = Array.from(
        document.querySelectorAll('input[name], textarea[name]'),
      ).find((node) =>
        node.name.includes(`[fieldsData][${identifier}][value]`),
      );
      if (!input || input.disabled || input.readOnly)
        throw new Error('This field is not editable.');
      const wrapper = input.closest('.ibexa-field-edit');
      if (wrapper?.classList.contains('ibexa-field-edit--nontranslatable'))
        throw new Error('This field cannot be translated.');
      const operation =
        find('operation').selectedOptions?.[0]?.dataset.type ||
        find('operation').value;
      if (!wrapper?.classList.contains('ibexa-field-edit--ibexa_richtext')) {
        return {
          identifier,
          input,
          original: input.value,
          source: input.value,
          append: operation === 'continue',
        };
      }
      const editor = editors.get(wrapper);
      if (!editor || editor.isReadOnly)
        throw new Error('The RichText editor is not ready.');
      const adapter = new window.ibexa.BaseRichText();
      const state = {
        identifier,
        input,
        editor,
        adapter,
        original: editor.getData(),
        source: '',
      };
      let html = state.original;
      if (find('scope').value === 'selection') {
        const selection = editor.model.document.selection;
        if (selection.isCollapsed || selection.rangeCount !== 1)
          throw new Error(
            'Select content in the RichText editor first, or choose the entire field.',
          );
        const range = selection.getFirstRange();
        state.range = editor.model.createRange(range.start, range.end);
        html = editor.data.stringify(
          editor.model.getSelectedContent(selection),
        );
      }
      // Use the exact XHTML serialization and namespace used by Ibexa's field form.
      state.richText = adapter
        .xhtmlify(html)
        .replace(adapter.xhtmlNamespace, adapter.ezNamespace);
      state.capabilities = {
        headingLevels: [1, 2, 3, 4, 5, 6].filter((level) =>
          editor.model.schema.isRegistered(`heading${level}`),
        ),
        tables: editor.model.schema.isRegistered('table'),
        customTags: Object.entries(window.ibexa.richText?.customTags || {})
          .filter(([, configuration]) => editor.model.schema.isRegistered(
            configuration.isInline ? 'inlineCustomTag' : 'customTag',
          ))
          .map(([name]) => name),
        lists:
          editor.plugins.has('List') || editor.plugins.has('ListProperties'),
      };
      return state;
    };

    generate.addEventListener('click', async () => {
      discard();
      generate.disabled = true;
      status.textContent = 'Generating suggestion…';
      try {
        const state = snapshot();
        const requestRevision = revision;
        const response = await fetch(panel.dataset.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': panel.dataset.csrf,
            Accept: 'application/json',
          },
          body: JSON.stringify({
            contentId: Number(panel.dataset.contentId),
            versionNo: Number(panel.dataset.versionNo),
            language: panel.dataset.language,
            field: state.identifier,
            action: find('operation').value,
            operation:
              find('operation').selectedOptions?.[0]?.dataset.type ||
              find('operation').value,
            instructions: find('instructions').value,
            source: state.source,
            richText: state.richText,
            editorCapabilities: state.capabilities,
          }),
          signal: AbortSignal.timeout(65000),
        });
        if (response.redirected)
          throw new Error('Your session expired. Reload the editor.');
        const data = await response.json();
        if (!response.ok)
          throw new Error(
            data.error || 'The assistant is unavailable. Please retry.',
          );
        if (requestRevision !== revision)
          throw new Error(
            'The assistant settings changed. Generate a fresh suggestion.',
          );
        if (state.editor) {
          if (
            typeof data.editXhtml !== 'string' ||
            typeof data.previewHtml !== 'string'
          )
            throw new Error(
              'The assistant returned an incomplete RichText suggestion.',
            );
          const wrapper = state.adapter.getHTMLDocumentFragment(data.editXhtml);
          if (!wrapper.firstChild)
            throw new Error(
              'The suggestion could not be loaded into the editor.',
            );
          // Upcast through the actual editor's schema before permitting application.
          const fragment = state.editor.data.parse(
            wrapper.firstChild.innerHTML,
          );
          state.richHtml = state.editor.data.stringify(fragment);
          find('formatted').srcdoc = previewDocument(data.previewHtml);
          find('preview-label').textContent = state.range
            ? 'Formatted preview of the selected content'
            : 'Formatted preview of the complete field';
        } else {
          if (typeof data.suggestion !== 'string')
            throw new Error('The assistant returned an incomplete suggestion.');
          state.suggestion = data.suggestion;
          find('source').value = state.source;
          find('result').value = data.suggestion;
        }
        find('plain').hidden = Boolean(state.editor);
        find('rich').hidden = !state.editor;
        pending = state;
        preview.hidden = false;
        status.textContent = 'Review the preview, then apply it to your draft.';
      } catch (error) {
        status.textContent =
          error instanceof SyntaxError
            ? 'The assistant is unavailable. Please reload and retry.'
            : error.message;
      } finally {
        generate.disabled = false;
      }
    });

    find('apply').addEventListener('click', () => {
      if (!pending) return;
      const { input, editor, original, range, richHtml, suggestion, append } =
        pending;
      if (
        !input.isConnected ||
        input.disabled ||
        input.readOnly ||
        editor?.isReadOnly ||
        (editor ? editor.getData() : input.value) !== original
      ) {
        status.textContent =
          'The field changed. Generate a fresh suggestion before applying.';
        discard();
        return;
      }
      try {
        if (editor) {
          if (range) {
            const fragment = editor.data.parse(richHtml);
            editor.model.change((writer) => {
              const selection = writer.createSelection(range);
              editor.model.deleteContent(selection);
              editor.model.insertContent(fragment, selection);
            });
          } else {
            // Native CKEditor data processing with an undoable batch. Ibexa's
            // existing change:data listener updates the form and autosave normally.
            editor.data.set(richHtml, { batchType: { isUndoable: true } });
          }
        } else {
          const text = append ? `${original}\n\n${suggestion}` : suggestion;
          if (input.maxLength >= 0 && text.length > input.maxLength)
            throw new Error(
              'The suggestion exceeds this field’s maximum length. Request a shorter version.',
            );
          input.value = text;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const changed = (editor ? editor.getData() : input.value) !== original;
        discard();
        status.textContent = changed
          ? 'Suggestion applied to the field. Review and save your draft as usual.'
          : 'The suggestion matches the current content; no changes were needed.';
      } catch (error) {
        status.textContent = `The suggestion could not be applied: ${error.message}`;
      }
    });
    find('discard').addEventListener('click', () => {
      discard();
      status.textContent = 'Suggestion discarded.';
    });
    ['field', 'operation', 'instructions', 'scope'].forEach((name) =>
      find(name).addEventListener('input', discard),
    );
    // Field controls open one shared dialog, retaining CKEditor's model selection.
    if (document.createElement) {
      const richTextFields = new Map();
      const open = (identifier) => {
        discard();
        if (identifier) find('field').value = identifier;
        status.textContent =
          'Choose an action, review the preview, then apply it.';
        if (!panel.open) panel.showModal();
      };
      const launcher = (label, identifier) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn ibexa-btn ibexa-btn--ghost ibexa-btn--small';
        button.textContent = label;
        button.setAttribute('aria-haspopup', 'dialog');
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => open(identifier));
        return button;
      };
      panel.before(launcher('AI actions'));
      for (const option of find('field').options) {
        const input = Array.from(
          document.querySelectorAll('input[name], textarea[name]'),
        ).find((node) =>
          node.name.includes(`[fieldsData][${option.value}][value]`),
        );
        const wrapper = input?.closest('.ibexa-field-edit');
        if (
          wrapper && !input.disabled && !input.readOnly &&
          !wrapper.classList.contains('ibexa-field-edit--nontranslatable')
        ) {
          if (wrapper.classList.contains('ibexa-field-edit--ibexa_richtext')) {
            richTextFields.set(wrapper, { identifier: option.value, input });
            continue;
          }
          const labelRow = wrapper.querySelector(
            '.ibexa-field-edit__label-wrapper',
          );
          if (labelRow) {
            labelRow.classList.add('wizhippo-ibexa-content-assistant-field-header');
            const button = launcher('AI actions', option.value);
            button.setAttribute(
              'aria-label',
              `AI actions for ${option.textContent.trim()}`,
            );
            labelRow.append(button);
          }
        }
      }
      document.addEventListener('wizhippo-content-assistant:open', (event) => {
        const wrapper = event.target.closest('.ibexa-field-edit');
        const field = richTextFields.get(wrapper);
        const editor = event.detail.editor;
        if (!field || field.input.disabled || field.input.readOnly || editor.isReadOnly)
          return;
        // Also retain the instance if this script loaded after instance-ready.
        editors.set(wrapper, editor);
        open(field.identifier);
      });
      find('close').addEventListener('click', () => panel.close());
      panel.addEventListener('close', discard);
    }
    generate.disabled = find('operation').options?.length === 0;
    status.textContent =
      'Choose a field and action, then generate a suggestion.';
  });
};

if (document.readyState === 'loading')
  document.addEventListener('DOMContentLoaded', initialise);
else initialise();
