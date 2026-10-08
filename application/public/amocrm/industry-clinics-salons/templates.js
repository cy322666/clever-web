define(function () {
    'use strict';

    var instance = null;

    return function (context) {
        if (!instance && context) {
            instance = context;
        }

        return function (template) {
            return instance.render({
                href: '/templates/' + template + '.twig',
                base_path: instance.params.path,
                v: instance.get_version(),
                promised: true
            });
        };
    };
});
