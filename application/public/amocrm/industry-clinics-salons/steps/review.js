define(['../templates.js', '../wizard-state.js'], function (getTemplateRenderer, wizardState) {
    'use strict';

    var getTemplate = getTemplateRenderer();

    return function (params) {
        var self = this;
        var integrationLangs = self.i18n('integrations');
        var selectedTitles = wizardState.integrations.map(function (code) {
            return integrationLangs[code] || code;
        });

        return getTemplate('review').then(function (template) {
            params.$el.append(template.render({
                organization: self.i18n('review')[wizardState.organization_format],
                integrations: selectedTitles.length
                    ? selectedTitles.join(', ')
                    : self.i18n('review').without_integrations,
                langs: {
                    organization: self.i18n('review').organization,
                    pipelines: self.i18n('review').pipelines,
                    samples: self.i18n('review').samples,
                    task: self.i18n('review').task,
                    integrations: self.i18n('review').integrations,
                    confirm: self.i18n('review').confirm
                }
            }));

            params.$el.on('click', '.js-industry-review-submit', function (event) {
                event.preventDefault();
                params.submit({ confirmed: true });
            });
        });
    };
});
