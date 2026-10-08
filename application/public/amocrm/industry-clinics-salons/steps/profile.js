define(['jquery', '../templates.js', '../wizard-state.js'], function ($, getTemplateRenderer, wizardState) {
    'use strict';

    var getTemplate = getTemplateRenderer();

    return function (params) {
        var self = this;
        var values = params.data || {};

        wizardState.organization_format = values.organization_format || wizardState.organization_format;

        return getTemplate('profile').then(function (template) {
            params.$el.append(template.render({
                values: values,
                organization_formats: [
                    { id: 'single', option: self.i18n('profile').single },
                    { id: 'network', option: self.i18n('profile').network }
                ],
                langs: {
                    organization_format: self.i18n('profile').organization_format,
                    standard_task: self.i18n('profile').standard_task,
                    submit: self.i18n('next_step')
                }
            }));

            params.$el.on('click', '.js-industry-profile-submit', function (event) {
                event.preventDefault();

                wizardState.organization_format = String(
                    params.$el.find('[name="organization_format"]').val() || 'single'
                );

                params.submit({ organization_format: wizardState.organization_format });
            });
        });
    };
});
