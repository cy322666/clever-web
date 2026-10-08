define([
    'underscore',
    './templates.js',
    './wizard-state.js',
    './steps/profile.js',
    './steps/integrations.js',
    './steps/review.js',
    './solution-config.js',
    './installer.js',
    'text!./templates/tutorial.md',
    'css!./styles.css'
], function (_, createTemplatesRenderer, wizardState, profileStep, integrationsStep, reviewStep, solutionConfig, installer, tutorial) {
    'use strict';

    return function () {
        var self = this;

        createTemplatesRenderer(this);

        this.callbacks = {
            init: function () {
                return true;
            },
            render: function () {
                return true;
            },
            bind_actions: function () {
                return true;
            },
            onSave: function () {
                return true;
            },
            register_steps: function () {
                return [
                    {
                        header: self.i18n('profile').header,
                        caption: self.i18n('profile').caption,
                        description: self.i18n('profile').description,
                        handler: _.bind(profileStep, self)
                    },
                    {
                        header: self.i18n('integrations').header,
                        caption: self.i18n('integrations').caption,
                        description: self.i18n('integrations').description,
                        handler: _.bind(integrationsStep, self)
                    },
                    {
                        header: self.i18n('review').header,
                        caption: self.i18n('review').caption,
                        description: self.i18n('review').description,
                        handler: _.bind(reviewStep, self)
                    }
                ];
            },
            finish_wizard: function (allStepsData) {
                return installer.run(allStepsData, solutionConfig).then(function () {
                    return { skip_tour: true };
                });
            },
            get_tutorial: function () {
                return tutorial;
            }
        };

        return this;
    };
});
