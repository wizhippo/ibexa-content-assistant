const path = require('path');

module.exports = (config, manager) => {
  manager.add({
    ibexaConfig: config,
    entryName: 'ibexa-richtext-onlineeditor-js',
    newItems: [path.resolve(__dirname, '../../assets/js/admin/content-assistant-richtext.js')],
  });
};
