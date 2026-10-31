const path = require('path');

module.exports = (config, manager) => {
  config.entry['wizhippo-ibexa-content-assistant-js'] = [
    path.resolve(__dirname, '../../assets/js/admin/content-assistant.js'),
  ];
  manager.add({
    ibexaConfig: config,
    entryName: 'ibexa-admin-ui-layout-css',
    newItems: [path.resolve(__dirname, '../../assets/scss/content-assistant.scss')],
  });
};
