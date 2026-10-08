define(['jquery', '../templates.js', '../wizard-state.js'], function ($, getTemplateRenderer, wizardState) {
    'use strict';

    var getTemplate = getTemplateRenderer();

    return function (params) {
        var self = this;
        var selected = (params.data && params.data.integrations) || params.data || [];

        if (!Array.isArray(selected)) {
            selected = [];
        }

        wizardState.integrations = selected.slice();

        return getTemplate('integrations').then(function (template) {
            params.width(880);
            params.$el.append(template.render({
                groups: [
                    group(self.i18n('integrations').group_booking, [
                        'yclients', 'sqns', 'vetmanager'
                    ]),
                    group(self.i18n('integrations').group_operations, [
                        'distribution', 'tilda'
                    ]),
                    group(self.i18n('integrations').group_channels, [
                        'max', 'telegram', 'whatsapp', 'vk', 'avito', 'instagram'
                    ])
                ],
                selected: selected,
                langs: {
                    submit: self.i18n('next_step')
                }
            }));

            params.$el
                .on('click', '.js-industry-integration-card', function (event) {
                    var $switcher = $(this).find('input[type="checkbox"]');

                    if (event.target !== $switcher[0] && !$(event.target).is('label')) {
                        $switcher.prop('checked', !$switcher.prop('checked')).trigger('change');
                    }
                })
                .on('click', '.js-industry-integrations-submit', function (event) {
                    event.preventDefault();

                    wizardState.integrations = params.$el.find('input[type="checkbox"]:checked')
                            .map(function () { return $(this).val(); })
                            .toArray();

                    params.submit({ integrations: wizardState.integrations });
                });
        });

        function group(title, codes) {
            return {
                title: title,
                integrations: codes.map(integration)
            };
        }

        function integration(code) {
            return {
                code: code,
                title: self.i18n('integrations')[code],
                description: self.i18n('integrations')[code + '_description']
            };
        }
    };
});
