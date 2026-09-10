define(['jquery'], function ($) {
    var DEFAULT_API_BASE = 'https://app.clevercrm.pro/api/amocrm';
    var BLOCK_ID = 'clever-vetmanager-widget';
    var LOGO_FILE = 'images/clever_mini_logo.png?v=1.0.0';

    var Widget = function () {
        var self = this;

        function apiBase() {
            var value = '';

            try {
                value = String((self.params && self.params.api_base_url) || '');
            } catch (e) {
                value = '';
            }

            return value ? value.replace(/\/$/, '') : DEFAULT_API_BASE;
        }

        function currentUserEmail() {
            var user = {};

            try {
                user = AMOCRM.constant('user') || {};
            } catch (e) {
                user = {};
            }

            return String(user.email || user.login || '');
        }

        function settingsUrl() {
            var email = encodeURIComponent(currentUserEmail());

            return apiBase() + '/widget?email=' + email + '&widget=vetmanager';
        }

        function logoUrl() {
            var path = '';

            try {
                path = String((self.params && self.params.path) || '');
            } catch (e) {
                path = '';
            }

            return path ? path.replace(/\/$/, '') + '/' + LOGO_FILE : LOGO_FILE;
        }

        function injectStyles() {
            if ($('#' + BLOCK_ID + '-styles').length) {
                return;
            }

            $('head').append(
                '<style id="' + BLOCK_ID + '-styles">' +
                '.clever-vetmanager{display:block;width:100%;margin:0;padding:0;background:#fff;color:#202226;font-family:inherit;border:0}' +
                '.clever-vetmanager__header{display:flex;align-items:center;gap:12px;padding:13px 18px;border-bottom:1px solid #ededed;background:#f5f5f5}' +
                '.clever-vetmanager__logo{display:block;width:34px;height:34px;flex:0 0 34px;object-fit:contain;border-radius:50%}' +
                '.clever-vetmanager__title{font-size:16px;font-weight:600;line-height:1.25;color:#202226}' +
                '.clever-vetmanager__body{padding:14px 18px 16px}' +
                '.clever-vetmanager__text{margin:0 0 12px;color:#64707d;font-size:13px;line-height:1.45}' +
                '.clever-vetmanager__button{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 13px;border:1px solid #f17822;border-radius:6px;background:#f17822;color:#fff;font-size:13px;font-weight:500;line-height:1.25;text-decoration:none;cursor:pointer}' +
                '.clever-vetmanager__button:hover{background:#d96516;color:#fff;text-decoration:none}' +
                '.clever-vetmanager-settings{margin:12px 0 0;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden}' +
                '</style>'
            );
        }

        function markup(id, settingsClass) {
            return [
                '<div id="' + id + '" class="clever-vetmanager ' + settingsClass + '">',
                '<div class="clever-vetmanager__header">',
                '<img class="clever-vetmanager__logo" src="' + logoUrl() + '" alt="">',
                '<div class="clever-vetmanager__title">Vetmanager Clever</div>',
                '</div>',
                '<div class="clever-vetmanager__body">',
                '<p class="clever-vetmanager__text">Посещения передаются в контакты и сделки. Покупки и транзакции не создаются.</p>',
                '<a class="clever-vetmanager__button js-clever-vetmanager-open" href="' + settingsUrl() + '" target="_blank" rel="noopener">Открыть настройки</a>',
                '</div>',
                '</div>'
            ].join('');
        }

        function mountLeadCard() {
            injectStyles();

            if ($('#' + BLOCK_ID).length) {
                return;
            }

            var $container = $('.card-widgets__widget-clever_vetmanager, .widgets-card__widgets, .card-widgets').first();

            if ($container.length) {
                $container.append(markup(BLOCK_ID, ''));
            }
        }

        function mountSettings() {
            injectStyles();

            if ($('#' + BLOCK_ID + '-settings').length) {
                return;
            }

            var $container = $('.widget-settings__desc-space, .widget_settings_block, .widgets-settings__body, .modal-body').first();

            if ($container.length) {
                $container.append(markup(BLOCK_ID + '-settings', 'clever-vetmanager-settings'));
            }
        }

        this.callbacks = {
            render: function () {
                mountLeadCard();

                return true;
            },
            init: function () {
                return true;
            },
            bind_actions: function () {
                $(document)
                    .off('click.cleverVetmanager')
                    .on('click.cleverVetmanager', '.js-clever-vetmanager-open', function (event) {
                        event.preventDefault();
                        window.open(settingsUrl(), '_blank', 'noopener');
                    });

                return true;
            },
            settings: function () {
                window.setTimeout(mountSettings, 100);

                return true;
            },
            onSave: function () {
                return true;
            },
            destroy: function () {
                $(document).off('click.cleverVetmanager');
            }
        };

        return this;
    };

    return Widget;
});
