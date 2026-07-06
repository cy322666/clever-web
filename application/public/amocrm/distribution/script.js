define(['jquery'], function ($) {
    var DEFAULT_API_BASE = 'https://app.clevercrm.pro/api/amocrm';
    var DEFAULT_APP_BASE = 'https://app.clevercrm.pro';
    var BLOCK_ID = 'clever-distribution-widget';
    var CAPTION_LOGO_FILE = 'images/clever_mini_logo.png?v=1.0.1';

    var Widget = function () {
        var self = this;

        function configuredApiBase() {
            var value = '';

            try {
                value = String((self.params && self.params.api_base_url) || '');
            } catch (e) {
                value = '';
            }

            return value ? value.replace(/\/$/, '') : DEFAULT_API_BASE;
        }

        function configuredAppBase() {
            return configuredApiBase().replace(/\/api\/amocrm$/, '') || DEFAULT_APP_BASE;
        }

        function userEmail() {
            var user = {};

            try {
                user = AMOCRM.constant('user') || {};
            } catch (e) {
                user = {};
            }

            return String(user.email || user.login || '');
        }

        function accountSubdomain() {
            var account = {};

            try {
                account = AMOCRM.constant('account') || {};
            } catch (e) {
                account = {};
            }

            return cleanSubdomain(account.subdomain || account.account_subdomain || window.location.hostname || '');
        }

        function cleanSubdomain(value) {
            value = String(value || '').replace(/^https?:\/\//, '').split('/')[0].toLowerCase();

            return value
                .replace(/\.amocrm\.ru$/, '')
                .replace(/\.amocrm\.com$/, '')
                .replace(/\.kommo\.com$/, '');
        }

        function currentArea() {
            try {
                return String((self.system && self.system().area) || '');
            } catch (e) {
                return '';
            }
        }

        function isLeadCardArea() {
            var area = currentArea();

            return area.indexOf('lcard') === 0 || /\/leads\/detail\/\d+/.test(String(window.location.pathname));
        }

        function captionLogoUrl() {
            var path = '';

            try {
                path = String((self.params && self.params.path) || '');
            } catch (e) {
                path = '';
            }

            return path ? path.replace(/\/$/, '') + '/' + CAPTION_LOGO_FILE : CAPTION_LOGO_FILE;
        }

        function cleverSettingsUrl() {
            var email = encodeURIComponent(userEmail());

            if (email) {
                return configuredApiBase() + '/widget?email=' + email + '&widget=distribution';
            }

            return configuredAppBase() + '/';
        }

        function injectStyles() {
            if ($('#' + BLOCK_ID + '-styles').length) {
                return;
            }

            $('head').append(
                '<style id="' + BLOCK_ID + '-styles">' +
                '.clever-distribution-card{display:block;width:100%;margin:0!important;padding:0!important;background:#fff;color:#202226;font-family:inherit;border:0;box-shadow:none}' +
                '.clever-distribution-card__header{display:flex;align-items:center;gap:12px;padding:14px 20px 12px;border-bottom:1px solid #ededed;background:#f5f5f5}' +
                '.clever-distribution-card__logo{display:block;width:34px;height:34px;flex:0 0 34px;object-fit:contain;border-radius:999px}' +
                '.clever-distribution-card__title{font-size:16px;font-weight:600;line-height:1.2;color:#f17822}' +
                '.clever-distribution-card__body{padding:14px 20px 16px;background:#fff}' +
                '.clever-distribution-card__text{margin:0 0 12px;color:#64707d;font-size:13px;line-height:1.45}' +
                '.clever-distribution-card__button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 14px;border:1px solid #dfdfdf;border-radius:6px;background:#f8f8f8;color:#202226;font-size:14px;font-weight:400;line-height:1.25;text-decoration:none;cursor:pointer;box-shadow:0 2px 7px rgba(17,24,39,.08),inset 0 1px 0 rgba(255,255,255,.9)}' +
                '.clever-distribution-card__button:hover{background:#fff6ef;border-color:#f17822;color:#202226;text-decoration:none}' +
                '.clever-distribution-settings{margin:12px 0 0;padding:14px 16px;border:1px solid #e5e7eb;border-radius:6px;background:#fff}' +
                '.clever-distribution-settings__title{margin:0 0 8px;color:#202226;font-size:15px;font-weight:600;line-height:1.25}' +
                '.clever-distribution-settings__text{margin:0 0 12px;color:#64707d;font-size:13px;line-height:1.45}' +
                '.clever-distribution-settings__button{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:7px 13px;border:1px solid #f17822;border-radius:6px;background:#f17822;color:#fff;font-size:13px;font-weight:500;line-height:1.25;text-decoration:none;cursor:pointer}' +
                '.clever-distribution-settings__button:hover{background:#d96516;color:#fff;text-decoration:none}' +
                '.clever-distribution-dp{margin-top:8px}' +
                '.clever-distribution-dp__select{width:100%;height:40px;border:1px solid #d7dadd;background:#fff;color:#313942;padding:0 10px;font-size:14px;outline:none}' +
                '.clever-distribution-dp__select:focus{border-color:#f17822}' +
                '.clever-distribution-dp__hint{margin-top:7px;color:#7a7068;font-size:12px;line-height:1.35}' +
                '.clever-distribution-dp__hint--error{color:#d1453b}' +
                '</style>'
            );
        }

        function renderCard() {
            injectStyles();

            return [
                '<div id="' + BLOCK_ID + '" class="clever-distribution-card">',
                '<div class="clever-distribution-card__header">',
                '<img class="clever-distribution-card__logo" src="' + captionLogoUrl() + '" alt="">',
                '<div class="clever-distribution-card__title">Распределение Clever</div>',
                '</div>',
                '<div class="clever-distribution-card__body">',
                '<p class="clever-distribution-card__text">Очереди настраиваются в Clever и доступны в бизнес-процессах amoCRM.</p>',
                '<a class="clever-distribution-card__button js-clever-distribution-open" href="' + cleverSettingsUrl() + '" target="_blank" rel="noopener">Открыть настройки</a>',
                '</div>',
                '</div>'
            ].join('');
        }

        function mountLeadCard() {
            if (!isLeadCardArea() || $('#' + BLOCK_ID).length) {
                return;
            }

            var $container = $('.card-widgets__widget-clever_distribution, .widgets-card__widgets, .card-widgets').first();

            if ($container.length) {
                $container.append(renderCard());
            }
        }

        function mountSettings() {
            injectStyles();

            if ($('#clever-distribution-settings').length) {
                return;
            }

            var $anchor = $('.widget-settings__desc-space, .widget_settings_block, .widgets-settings__body, .modal-body').first();
            var html = [
                '<div id="clever-distribution-settings" class="clever-distribution-settings">',
                '<div class="clever-distribution-settings__title">Распределение Clever</div>',
                '<p class="clever-distribution-settings__text">Создайте очереди в Clever, затем выберите нужную очередь в бизнес-процессе воронки amoCRM.</p>',
                '<a class="clever-distribution-settings__button js-clever-distribution-open" href="' + cleverSettingsUrl() + '" target="_blank" rel="noopener">Открыть Clever</a>',
                '</div>'
            ].join('');

            if ($anchor.length) {
                $anchor.append(html);
            }
        }

        function openCleverSettings() {
            window.open(cleverSettingsUrl(), '_blank', 'noopener');
        }

        function request(method, url, data, onSuccess, onError) {
            var payload = data || {};
            var completed = false;
            var timer = window.setTimeout(function () {
                if (!completed) {
                    completed = true;
                    onError({timeout: true});
                }
            }, 10000);

            function success(response) {
                if (completed) {
                    return;
                }

                completed = true;
                window.clearTimeout(timer);
                onSuccess(parseResponse(response));
            }

            function fail(error) {
                if (completed) {
                    return;
                }

                completed = true;
                window.clearTimeout(timer);
                onError(error || {});
            }

            if (method === 'GET') {
                var query = $.param(payload);
                url += query ? '?' + query : '';
                payload = {};
            }

            if (typeof self.crm_post === 'function') {
                self.crm_post(
                    url,
                    payload,
                    success,
                    'json',
                    fail
                );

                return;
            }

            $.ajax({
                url: url,
                method: method,
                data: method === 'GET' ? payload : JSON.stringify(payload),
                contentType: 'application/json',
                dataType: 'json'
            }).done(success).fail(fail);
        }

        function parseResponse(response) {
            if (typeof response === 'string') {
                try {
                    return JSON.parse(response);
                } catch (e) {
                    return {};
                }
            }

            return response || {};
        }

        function renderDpSettings() {
            injectStyles();

            var $input = $('input[name="queue_uuid"], input[name$="[queue_uuid]"]').first();

            if (!$input.length) {
                window.setTimeout(renderDpSettings, 150);
                return;
            }

            if ($('#clever-distribution-dp-settings').length) {
                return;
            }

            var currentValue = String($input.val() || '');
            var $field = $input.closest('.widget_settings_block__input_field, .control-wrapper, .linked-form__field, .js-widget-settings__field');
            var $container = $('<div id="clever-distribution-dp-settings" class="clever-distribution-dp">' +
                '<select class="clever-distribution-dp__select" disabled><option>Загрузка очередей...</option></select>' +
                '<div class="clever-distribution-dp__hint">Выберите очередь, на которую amoCRM отправит сделку при срабатывании бизнес-процесса.</div>' +
                '</div>');

            $input.attr('type', 'hidden');

            if ($field.length) {
                $field.after($container);
            } else {
                $input.after($container);
            }

            request(
                'GET',
                configuredApiBase() + '/distribution',
                {
                    subdomain: accountSubdomain()
                },
                function (response) {
                    var queues = response.queues || [];
                    var $select = $container.find('select');

                    if (!response.ok) {
                        $select.html('<option value="">' + escapeHtml(response.message || 'Очереди недоступны') + '</option>');
                        $container.find('.clever-distribution-dp__hint').addClass('clever-distribution-dp__hint--error');
                        return;
                    }

                    if (!queues.length) {
                        $select.html('<option value="">Нет настроенных очередей</option>');
                        return;
                    }

                    $select.html('<option value="">Выберите очередь</option>' + queues.map(function (queue) {
                        return '<option value="' + escapeHtml(queue.id) + '">' + escapeHtml(queue.name || ('Очередь ' + queue.id)) + '</option>';
                    }).join(''));

                    $select.prop('disabled', false).val(currentValue);
                    $select.on('change', function () {
                        $input.val($(this).val()).trigger('input').trigger('change');
                    });
                },
                function () {
                    $container.find('select').html('<option value="">Не удалось загрузить очереди</option>');
                    $container.find('.clever-distribution-dp__hint').addClass('clever-distribution-dp__hint--error');
                }
            );
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
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
                    .off('click.cleverDistribution')
                    .on('click.cleverDistribution', '.js-clever-distribution-open', function (event) {
                        event.preventDefault();
                        window.setTimeout(openCleverSettings, 0);
                    });

                return true;
            },
            settings: function () {
                window.setTimeout(mountSettings, 100);

                return true;
            },
            dpSettings: function () {
                renderDpSettings();

                return true;
            },
            onSave: function () {
                return true;
            },
            destroy: function () {
                $(document).off('click.cleverDistribution');
            }
        };

        return this;
    };

    return Widget;
});
