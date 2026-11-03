const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/admin/content-assistant.js'), 'utf8');

function editorPage(response = { suggestion: 'A new title' }, editor = null, emitReady = true, customTags = {}) {
  const controls = new Map();
  for (const name of ['field', 'operation', 'scope', 'instructions', 'status', 'preview', 'plain', 'rich', 'formatted', 'preview-label', 'generate', 'source', 'result', 'apply', 'discard', 'close']) {
    controls.set(name, { value: '', hidden: name === 'preview', listeners: {}, addEventListener(type, listener) { this.listeners[type] = listener; } });
  }
  controls.get('field').value = editor ? 'body' : 'title';
  controls.get('operation').value = 'rewrite';
  controls.get('scope').value = 'field';
  controls.get('instructions').value = 'Improve the presentation with headings and a table.';
  const launchers = [];
  const wrapper = { querySelector: () => ({ classList: { add() {} }, append: (button) => launchers.push(button) }), classList: { contains: (name) => Boolean(editor) && name === 'ibexa-field-edit--ibexa_richtext' } };
  const input = { name: `ezplatform_content_forms_content_edit[fieldsData][${editor ? 'body' : 'title'}][value]`, value: 'Original title', maxLength: 255, isConnected: true, closest: () => wrapper, dispatchEvent() {} };
  const panel = { dataset: { endpoint: '/admin/content-assistant/suggest', csrf: 'test', contentId: '12', versionNo: '2', language: 'eng-GB' }, querySelector: (selector) => controls.get(selector.slice('[data-assistant-'.length, -1)) };
  panel.before = (button) => launchers.push(button);
  panel.listeners = {};
  panel.addEventListener = (name, callback) => { panel.listeners[name] = callback; };
  panel.showModal = () => { panel.open = true; };
  panel.close = () => { panel.open = false; panel.listeners.close(); };
  controls.get('field').options = [{ value: editor ? 'body' : 'title', textContent: editor ? 'Body' : 'Title' }];
  const requests = [];
  const listeners = {};
  const document = { createElement: () => ({ listeners: {}, setAttribute() {}, addEventListener(name, callback) { this.listeners[name] = callback; } }), readyState: 'complete', addEventListener: (type, listener) => { listeners[type] = listener; }, querySelectorAll: (selector) => selector === '[data-content-assistant]' ? [panel] : selector.startsWith('link') ? [{ href: 'https://localhost/editor.css' }] : [input] };
  class Adapter {
    xhtmlNamespace = 'http://www.w3.org/1999/xhtml';
    ezNamespace = 'http://ibexa.co/namespaces/ezpublish5/xhtml5/edit';
    xhtmlify(html) { return `<section xmlns="${this.xhtmlNamespace}">${html}</section>`; }
    getHTMLDocumentFragment(xml) { return { firstChild: { innerHTML: xml.replace(/^<section[^>]*>/, '').replace(/<\/section>$/, '') } }; }
  }
  vm.runInNewContext(source, {
    document, Event, AbortSignal,
    window: { location: { origin: 'https://localhost' }, ibexa: { BaseRichText: Adapter, richText: { customTags } } },
    fetch: async (url, options) => { requests.push({ url, data: JSON.parse(options.body) }); return { ok: !response.error, json: async () => response }; },
  });
  if (editor && emitReady) listeners['ibexa-ckeditor:instance-ready']({ target: { closest: () => wrapper }, detail: { editor } });
  return { controls, input, requests, panel, launchers, openToolbar: () => listeners['wizhippo-content-assistant:open']({ target: { closest: () => wrapper }, detail: { editor } }), click: (name) => controls.get(name).listeners.click() };
}

function richEditor() {
  let html = '<p>Original body</p>';
  const calls = [];
  const range = { start: { path: [0, 0] }, end: { path: [0, 4] } };
  const editor = {
    getData: () => html,
    plugins: { has: (name) => name === 'ListProperties' },
    data: {
      parse: (html) => ({ html }), stringify: (fragment) => fragment.html,
      set: (data, options) => { calls.push({ data, options }); html = data; },
    },
    model: {
      schema: { isRegistered: (name) => ['heading1', 'heading2', 'table'].includes(name) },
      document: { selection: { isCollapsed: false, rangeCount: 1, getFirstRange: () => range } },
      createRange: (start, end) => ({ start, end }),
      getSelectedContent: () => ({ html: '<p>Selected content</p>' }),
      change: (apply) => apply({ createSelection: (range) => ({ range }) }),
      deleteContent: (selection) => calls.push({ deleted: selection }),
      insertContent: (fragment, selection) => { calls.push({ inserted: fragment.html, selection }); html = fragment.html; },
    },
  };
  return { editor, calls };
}
const richResponse = {
  editXhtml: '<section xmlns="http://ibexa.co/namespaces/ezpublish5/xhtml5/edit"><h2>Itinerary</h2><table><tbody><tr><td>San Juan</td></tr></tbody></table></section>',
  previewHtml: '<h2>Itinerary</h2><table><tbody><tr><td>San Juan</td></tr></tbody></table><img src="/protected-embed.jpg" alt="Existing embed">',
};

test('assistant loads independently of third-party editor scripts', () => {
  const original = ['vendor-editor.js'];
  const config = { entry: { 'ibexa-admin-ui-content-edit-parts-js': original } };
  require('../../Resources/encore/ibexa.config.manager.js')(config, { add() {} });
  assert.equal(config.entry['ibexa-admin-ui-content-edit-parts-js'], original);
  assert.ok(config.entry['wizhippo-ibexa-content-assistant-js'][0].endsWith('/assets/js/admin/content-assistant.js'));
});
test('plain suggestions are previewed before application', async () => {
  const page = editorPage();
  await page.click('generate');
  assert.equal(page.controls.get('result').value, 'A new title');
  assert.equal(page.input.value, 'Original title');
  page.click('apply');
  assert.equal(page.input.value, 'A new title');
});
test('stale suggestions never overwrite user changes', async () => {
  const page = editorPage(); await page.click('generate');
  page.input.value = 'Manual revision'; page.click('apply');
  assert.equal(page.input.value, 'Manual revision');
  assert.match(page.controls.get('status').textContent, /field changed/);
});
test('provider errors are visible and reenable generation', async () => {
  const page = editorPage({ error: 'Invalid RichText suggestion.' }); await page.click('generate');
  assert.equal(page.controls.get('status').textContent, 'Invalid RichText suggestion.');
  assert.equal(page.controls.get('generate').disabled, false);
  assert.equal(page.controls.get('preview').hidden, true);
});
test('continuation appends to a plain field', async () => {
  const page = editorPage({ suggestion: 'Next paragraph.' }); page.controls.get('operation').value = 'continue';
  await page.click('generate'); page.click('apply');
  assert.equal(page.input.value, 'Original title\n\nNext paragraph.');
});
test('RichText sends Ibexa editor XHTML and actual supported capabilities', async () => {
  const { editor } = richEditor(); const page = editorPage(richResponse, editor);
  await page.click('generate');
  const request = page.requests[0].data;
  assert.match(request.richText, /http:\/\/ibexa.co\/namespaces\/ezpublish5\/xhtml5\/edit/);
  assert.deepEqual(request.editorCapabilities, { headingLevels: [1, 2], tables: true, customTags: [], lists: true });
  assert.equal('segments' in request, false);
  assert.equal(page.controls.get('plain').hidden, true);
  assert.equal(page.controls.get('rich').hidden, false);
  assert.match(page.controls.get('formatted').srcdoc, /<h2>Itinerary<\/h2>/);
  assert.match(page.controls.get('formatted').srcdoc, /<table>/);
  assert.match(page.controls.get('formatted').srcdoc, /protected-embed.jpg/);
  assert.match(page.controls.get('formatted').srcdoc, /editor.css/);
});
test('native formatted application uses an undoable CKEditor data batch', async () => {
  const { editor, calls } = richEditor(); const page = editorPage(richResponse, editor);
  await page.click('generate'); page.click('apply');
  assert.equal(calls.length, 1);
  assert.match(editor.getData(), /<h2>Itinerary<\/h2>/);
  assert.match(editor.getData(), /<table>/);
  assert.equal(calls[0].options.batchType.isUndoable, true);
  assert.match(page.controls.get('status').textContent, /Suggestion applied/);
});
test('selected content uses the native insertion pipeline and identifies preview scope', async () => {
  const { editor, calls } = richEditor(); const page = editorPage(richResponse, editor);
  page.controls.get('scope').value = 'selection'; await page.click('generate');
  assert.match(page.requests[0].data.richText, /Selected content/);
  assert.match(page.controls.get('preview-label').textContent, /selected content/);
  page.click('apply');
  assert.equal(calls.length, 2);
  assert.match(calls[1].inserted, /<table>/);
});
test('unchanged suggestions do not falsely report an applied edit', async () => {
  const page = editorPage({ suggestion: 'Original title' }); await page.click('generate'); page.click('apply');
  assert.match(page.controls.get('status').textContent, /no changes were needed/);
});
test('rendered preview is sandboxed without script permissions', () => {
  const template = fs.readFileSync(path.resolve(__dirname, '../../templates/admin/content_assistant.html.twig'), 'utf8');
  assert.match(template, /sandbox="allow-same-origin"/);
  assert.doesNotMatch(template, /allow-scripts/);
});

test('RichText toolbar action opens the field dialog and closing discards the preview', async () => {
  const { editor } = richEditor(); const page = editorPage(richResponse, editor);
  assert.equal(page.launchers.length, 0, 'RichText uses only its toolbar launcher');
  page.openToolbar();
  assert.equal(page.panel.open, true);
  assert.equal(page.controls.get('field').value, 'body');
  assert.equal(page.controls.get('scope').value, 'field');
  await page.click('generate');
  assert.equal(page.controls.get('preview').hidden, false);
  page.click('close');
  assert.equal(page.panel.open, false);
  assert.equal(page.controls.get('preview').hidden, true);
});
test('custom action configurations use the developer action type for continuation behavior', async () => {
  const page = editorPage({ suggestion: 'Continued body.' });
  page.controls.get('operation').value = 'custom_continuation';
  page.controls.get('operation').selectedOptions = [{ dataset: { type: 'continue' } }];
  await page.click('generate');
  assert.equal(page.requests[0].data.action, 'custom_continuation');
  assert.equal(page.requests[0].data.operation, 'continue');
  page.click('apply');
  assert.equal(page.input.value, 'Original title\n\nContinued body.');
});

test('plain fields retain their field-label launcher', () => {
  const page = editorPage();
  assert.equal(page.launchers.length, 1, 'Plain fields use only their field-label launcher');
  assert.equal(page.launchers[0].textContent, 'AI actions');
  page.launchers[0].listeners.click();
  assert.equal(page.panel.open, true);
  assert.equal(page.controls.get('field').value, 'title');
});
test('toolbar opening retains the model selection even when instance-ready was missed', async () => {
  const { editor } = richEditor();
  const page = editorPage(richResponse, editor, false);
  page.controls.get('scope').value = 'selection';
  page.openToolbar();
  await page.click('generate');
  assert.equal(page.panel.open, true);
  assert.match(page.requests[0].data.richText, /Selected content/);
});
test('read-only editors cannot open the toolbar action dialog', () => {
  const { editor } = richEditor();
  const page = editorPage(richResponse, editor);
  editor.isReadOnly = true;
  page.openToolbar();
  assert.equal(page.panel.open, undefined);
});

test('RichText reports only custom-tag kinds supported by its editor schema', async () => {
  const { editor } = richEditor();
  const original = editor.model.schema.isRegistered;
  editor.model.schema.isRegistered = (name) => name === 'customTag' || original(name);
  const page = editorPage(richResponse, editor, true, { factbox: { isInline: false }, badge: { isInline: true } });
  await page.click('generate');
  assert.deepEqual(page.requests[0].data.editorCapabilities.customTags, ['factbox']);
});
