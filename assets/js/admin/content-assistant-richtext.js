import { ButtonView, Plugin } from 'ckeditor5';

const componentName = 'wizhippoContentAssistant';
const openEvent = 'wizhippo-content-assistant:open';

class ContentAssistantRichText extends Plugin {
  static get pluginName() {
    return 'WizhippoContentAssistant';
  }

  init() {
    const editor = this.editor;
    editor.ui.componentFactory.add(componentName, (locale) => {
      const button = new ButtonView(locale);
      button.set({ label: 'AI actions', withText: true, tooltip: true });
      button.extendTemplate({ attributes: { 'aria-haspopup': 'dialog' } });
      button.bind('isEnabled').to(
        this, 'isEnabled', editor, 'isReadOnly',
        (isEnabled, isReadOnly) => isEnabled && !isReadOnly,
      );
      this.listenTo(button, 'execute', () => {
        editor.sourceElement.dispatchEvent(new CustomEvent(openEvent, {
          bubbles: true,
          detail: { editor },
        }));
      });
      return button;
    });
  }
}

// Register within Ibexa's RichText build so the plugin uses the same CKEditor
// modules as the editor. A second CKEditor bundle would cause duplicate modules.
document.addEventListener('ibexa-ckeditor:configure', ({ detail }) => {
  const { container, config } = detail;
  const field = container.closest('.ibexa-field-edit');
  if (!field || field.classList.contains('ibexa-field-edit--nontranslatable')) return;
  const inputs = Array.from(field.querySelectorAll('input[name], textarea[name]'));
  const panels = Array.from(document.querySelectorAll('[data-content-assistant]'));
  const allowed = panels.some((panel) =>
    panel.querySelector('[data-assistant-operation]').options.length > 0 &&
    Array.from(panel.querySelector('[data-assistant-field]').options).some((option) =>
      inputs.some((input) => !input.disabled && !input.readOnly &&
        input.name.includes(`[fieldsData][${option.value}][value]`)),
    ),
  );
  if (!allowed) return;

  config.extraPlugins = [...(config.extraPlugins || [])];
  if (!config.extraPlugins.includes(ContentAssistantRichText))
    config.extraPlugins.push(ContentAssistantRichText);
  const toolbar = Array.isArray(config.toolbar) ? { items: config.toolbar } : config.toolbar;
  const items = [...(toolbar?.items || [])];
  if (!items.includes(componentName)) {
    if (items.length && items.at(-1) !== '|') items.push('|');
    items.push(componentName);
  }
  config.toolbar = { ...toolbar, items };
}, true);
