const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/admin/content-assistant-richtext.js'), 'utf8');

function toolbarPage({ allowed = true, disabled = false, readOnly = false, translatable = true, actions = true } = {}) {
  const listeners = {};
  const input = { name: 'content[fieldsData][body][value]', disabled, readOnly };
  const field = {
    classList: { contains: (name) => name === 'ibexa-field-edit--nontranslatable' && !translatable },
    querySelectorAll: () => [input],
  };
  const panel = { querySelector: (selector) => ({ options: selector === '[data-assistant-operation]' ? (actions ? [{}] : []) : [{ value: allowed ? 'body' : 'title' }] }) };
  const container = { closest: () => field };
  class Plugin {
    constructor(editor) { this.editor = editor; this.isEnabled = true; }
    listenTo(view, name, listener) { view.listeners[name] = listener; }
  }
  class ButtonView {
    listeners = {};
    set(options) { Object.assign(this, options); }
    extendTemplate(template) { this.template = template; }
    bind(property) {
      return { to: (plugin, pluginProperty, editor, editorProperty, transform) => {
        Object.defineProperty(this, property, { get: () => transform(plugin[pluginProperty], editor[editorProperty]) });
      } };
    }
  }
  vm.runInNewContext(source.replace(/^import .*;\n/, ''), {
    Plugin, ButtonView, CustomEvent,
    document: {
      addEventListener: (name, listener) => { listeners[name] = listener; },
      querySelectorAll: () => [panel],
    },
  });
  return { container, configure: (config) => listeners['ibexa-ckeditor:configure']({ detail: { container, config } }) };
}

test('the toolbar plugin is bundled with Ibexa’s existing CKEditor entry', () => {
  const config = { entry: { 'ibexa-richtext-onlineeditor-js': ['base-ckeditor.js'] } };
  require('../../Resources/encore/ibexa.richtext.config.manager.js')(config, {
    add: ({ ibexaConfig, entryName, newItems }) => ibexaConfig.entry[entryName].push(...newItems),
  });
  assert.equal(Object.keys(config.entry).length, 1);
  assert.equal(config.entry['ibexa-richtext-onlineeditor-js'][0], 'base-ckeditor.js');
  assert.ok(config.entry['ibexa-richtext-onlineeditor-js'][1].endsWith('/content-assistant-richtext.js'));
});

test('configuration preserves toolbar options and does not duplicate plugins or buttons', () => {
  const page = toolbarPage();
  const originalItems = ['bold'];
  const config = { toolbar: { items: originalItems, shouldNotGroupWhenFull: true } };
  page.configure(config);
  page.configure(config);
  assert.deepEqual(Array.from(config.toolbar.items), ['bold', '|', 'wizhippoContentAssistant']);
  assert.deepEqual(originalItems, ['bold']);
  assert.equal(config.toolbar.shouldNotGroupWhenFull, true);
  assert.equal(config.extraPlugins.length, 1);
});

test('array toolbar configurations are supported', () => {
  const page = toolbarPage();
  const config = { toolbar: ['bold', '|'] };
  page.configure(config);
  assert.deepEqual(Array.from(config.toolbar.items), ['bold', '|', 'wizhippoContentAssistant']);
});

for (const [name, options] of [
  ['fields outside the allowlist', { allowed: false }],
  ['disabled fields', { disabled: true }],
  ['read-only fields', { readOnly: true }],
  ['nontranslatable fields', { translatable: false }],
  ['no enabled actions', { actions: false }],
]) {
  test(`no toolbar button is added for ${name}`, () => {
    const page = toolbarPage(options);
    const config = { toolbar: ['bold'] };
    page.configure(config);
    assert.equal(config.extraPlugins, undefined);
    assert.deepEqual(config.toolbar, ['bold']);
  });
}

test('the native button opens the owning editor and follows its read-only state', () => {
  const page = toolbarPage();
  const config = { toolbar: ['bold'] };
  page.configure(config);
  let factory;
  const events = [];
  const editor = {
    isReadOnly: false,
    sourceElement: { dispatchEvent: (event) => events.push(event) },
    ui: { componentFactory: { add: (name, callback) => { assert.equal(name, 'wizhippoContentAssistant'); factory = callback; } } },
  };
  const plugin = new config.extraPlugins[0](editor);
  plugin.init();
  const button = factory({});
  assert.equal(button.label, 'AI actions');
  assert.equal(button.template.attributes['aria-haspopup'], 'dialog');
  assert.equal(button.isEnabled, true);
  button.listeners.execute();
  assert.equal(events[0].type, 'wizhippo-content-assistant:open');
  assert.equal(events[0].detail.editor, editor);
  assert.equal(events[0].bubbles, true);
  editor.isReadOnly = true;
  assert.equal(button.isEnabled, false);
  editor.isReadOnly = false;
  plugin.isEnabled = false;
  assert.equal(button.isEnabled, false);
});
