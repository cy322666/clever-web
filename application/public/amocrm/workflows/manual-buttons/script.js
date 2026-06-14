define(['jquery'], function ($) {
    var API_BASE = 'https://app.clevercrm.pro/api/amocrm/workflows/manual-buttons';
    var BLOCK_ID = 'clever-workflow-buttons';
    var LOAD_RETRIES = 0;

    var Widget = function () {
        var self = this;

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

        function currentLeadId() {
            var card = {};

            try {
                card = AMOCRM.data.current_card || {};
            } catch (e) {
                card = {};
            }

            if (card.id) {
                return String(card.id);
            }

            var match = String(window.location.pathname).match(/\/leads\/detail\/(\d+)/);

            return match ? match[1] : '';
        }

        function currentLeadName() {
            var card = {};

            try {
                card = AMOCRM.data.current_card || {};
            } catch (e) {
                card = {};
            }

            return card.name || $('.linked-form__field__value-name input').val() || '';
        }

        function injectStyles() {
            if ($('#' + BLOCK_ID + '-styles').length) {
                return;
            }

            $('head').append(
                '<style id="' + BLOCK_ID + '-styles">' +
                '.clever-workflow-card{padding:14px 16px;border:0;background:#fff;color:#181411;font-family:inherit;box-shadow:none}' +
                '.clever-workflow-card__head{display:flex;align-items:center;margin:0 0 8px}' +
                '.clever-workflow-card__mark{display:none}' +
                '.clever-workflow-card__title{font-size:16px;font-weight:700;margin:0;color:#181411;letter-spacing:-.01em}' +
                '.clever-workflow-card__list{display:flex;flex-direction:column;gap:0;border-top:1px solid #eee7e1}' +
                '.clever-workflow-card__button{position:relative;width:100%;border:0;border-bottom:1px solid #eee7e1;background:#fff;color:#181411;min-height:38px;padding:9px 8px 9px 16px;font-size:14px;font-weight:600;cursor:pointer;text-align:left;box-shadow:none;transition:background .15s ease,color .15s ease}' +
                '.clever-workflow-card__button:before{content:"";position:absolute;left:0;top:12px;bottom:12px;width:3px;background:#f17822}' +
                '.clever-workflow-card__button:hover{background:#fff8f3;color:#c95c16}' +
                '.clever-workflow-card__button:disabled{cursor:default;opacity:.65;transform:none}' +
                '.clever-workflow-card__empty,.clever-workflow-card__status{font-size:13px;line-height:1.45;color:#7a7068}' +
                '.clever-workflow-card__empty{padding:4px 0}' +
                '.clever-workflow-card__status{margin-top:8px}' +
                '.clever-workflow-card__status--ok{color:#25884f}' +
                '.clever-workflow-card__status--error{color:#d1453b}' +
                '.clever-workflow-card__loader{display:inline-flex;align-items:center;gap:8px}' +
                '.clever-workflow-card__loader:before{content:"";width:12px;height:12px;border:2px solid #f5c3a2;border-top-color:#f17822;border-radius:999px;animation:cleverWorkflowSpin .8s linear infinite}' +
                '.clever-workflow-dp{margin-top:8px}' +
                '.clever-workflow-dp__select{width:100%;height:40px;border:1px solid #d7dadd;background:#fff;color:#313942;padding:0 10px;font-size:14px;outline:none}' +
                '.clever-workflow-dp__select:focus{border-color:#f17822}' +
                '.clever-workflow-dp__hint{margin-top:7px;color:#7a7068;font-size:12px;line-height:1.35}' +
                '.clever-workflow-dp__hint--error{color:#d1453b}' +
                '@keyframes cleverWorkflowSpin{to{transform:rotate(360deg)}}' +
                '</style>'
            );
        }

        function renderShell() {
            injectStyles();

            return [
                '<div id="' + BLOCK_ID + '" class="clever-workflow-card">',
                '<div class="clever-workflow-card__head">',
                '<div class="clever-workflow-card__mark">C</div>',
                '<div class="clever-workflow-card__title">Сценарии</div>',
                '</div>',
                '<div class="clever-workflow-card__list">',
                '<div class="clever-workflow-card__empty clever-workflow-card__loader">Загрузка сценариев</div>',
                '</div>',
                '<div class="clever-workflow-card__status"></div>',
                '</div>'
            ].join('');
        }

        function mount() {
            var html = renderShell();

            if (typeof self.render_template === 'function') {
                self.render_template({
                    caption: {
                        class_name: 'clever_workflow_buttons_caption',
                        html: ''
                    },
                    body: '',
                    render: html
                });
            } else if (!$('#' + BLOCK_ID).length) {
                $('.card-widgets__widgets, .widgets__list, .linked-form__right, body').first().prepend(html);
            }

            scheduleLoadButtons();
        }

        function request(method, url, data, onSuccess, onError) {
            var payload = data || {};
            var completed = false;
            var timer = window.setTimeout(function () {
                if (!completed) {
                    completed = true;
                    onError({ timeout: true });
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

        function scheduleLoadButtons() {
            window.setTimeout(loadButtons, 120);
        }

        function loadButtons() {
            var leadId = currentLeadId();
            var subdomain = accountSubdomain();
            var $block = $('#' + BLOCK_ID);
            var $list = $block.find('.clever-workflow-card__list');
            var $status = $block.find('.clever-workflow-card__status');

            if (!$block.length || !$list.length) {
                if (LOAD_RETRIES < 10) {
                    LOAD_RETRIES += 1;
                    scheduleLoadButtons();
                }

                return;
            }

            if (!leadId || !subdomain) {
                $list.html('<div class="clever-workflow-card__empty">Откройте карточку сделки.</div>');
                return;
            }

            request(
                'GET',
                API_BASE,
                {
                    subdomain: subdomain,
                    lead_id: leadId
                },
                function (response) {
                    var workflows = response.workflows || [];

                    if (!response.ok) {
                        $list.html('<div class="clever-workflow-card__empty">' + escapeHtml(response.message || 'Сценарии недоступны.') + '</div>');
                        return;
                    }

                    if (!workflows.length) {
                        $list.html('<div class="clever-workflow-card__empty">Нет включенных ручных сценариев.</div>');
                        return;
                    }

                    $list.html(workflows.map(function (workflow) {
                        return '<button type="button" class="clever-workflow-card__button" data-workflow-id="' + workflow.id + '">' +
                            escapeHtml(workflow.name || ('Сценарий #' + workflow.id)) +
                            '</button>';
                    }).join(''));

                    $status.text('');
        },
        function () {
                    $list.html('<div class="clever-workflow-card__empty">Не удалось загрузить сценарии. Проверьте подключение Clever.</div>');
                }
            );
        }

        function runWorkflow($button) {
            var workflowId = $button.data('workflow-id');
            var leadId = currentLeadId();
            var $block = $('#' + BLOCK_ID);
            var $buttons = $block.find('.clever-workflow-card__button');
            var $status = $block.find('.clever-workflow-card__status');
            var originalText = $button.text();

            $buttons.prop('disabled', true);
            $button.text('Запускаю...');
            $status.removeClass('clever-workflow-card__status--ok clever-workflow-card__status--error').text('');

            request(
                'POST',
                API_BASE + '/run',
                {
                    subdomain: accountSubdomain(),
                    workflow_id: workflowId,
                    lead_id: leadId,
                    lead_name: currentLeadName()
                },
                function (response) {
                    if (response.ok) {
                        $status.removeClass('clever-workflow-card__status--ok clever-workflow-card__status--error').text('');
                    } else {
                        $status.addClass('clever-workflow-card__status--error').text(response.message || 'Не удалось запустить сценарий.');
                    }
                },
                function () {
                    $status.addClass('clever-workflow-card__status--error').text('Не удалось запустить сценарий.');
                }
            );

            window.setTimeout(function () {
                $button.text(originalText);
                $buttons.prop('disabled', false);
            }, 1200);
        }

        function renderDpSettings() {
            injectStyles();

            var $input = $('input[name="workflow_id"], input[name$="[workflow_id]"]').first();

            if (!$input.length) {
                window.setTimeout(renderDpSettings, 150);
                return;
            }

            if ($('#clever-workflow-dp-settings').length) {
                return;
            }

            var currentValue = String($input.val() || '');
            var $field = $input.closest('.widget_settings_block__input_field, .control-wrapper, .linked-form__field, .js-widget-settings__field');
            var $container = $('<div id="clever-workflow-dp-settings" class="clever-workflow-dp">' +
                '<select class="clever-workflow-dp__select" disabled><option>Загрузка сценариев...</option></select>' +
                '<div class="clever-workflow-dp__hint">Выберите ручной сценарий, который amoCRM запустит при срабатывании действия в воронке.</div>' +
                '</div>');

            $input.attr('type', 'hidden');

            if ($field.length) {
                $field.after($container);
            } else {
                $input.after($container);
            }

            request(
                'GET',
                API_BASE,
                {
                    subdomain: accountSubdomain()
                },
                function (response) {
                    var workflows = response.workflows || [];
                    var $select = $container.find('select');

                    if (!response.ok) {
                        $select.html('<option value="">' + escapeHtml(response.message || 'Сценарии недоступны') + '</option>');
                        $container.find('.clever-workflow-dp__hint').addClass('clever-workflow-dp__hint--error');
                        return;
                    }

                    if (!workflows.length) {
                        $select.html('<option value="">Нет включенных ручных сценариев</option>');
                        return;
                    }

                    $select.html('<option value="">Выберите сценарий</option>' + workflows.map(function (workflow) {
                        return '<option value="' + workflow.id + '">' + escapeHtml(workflow.name || ('Сценарий #' + workflow.id)) + '</option>';
                    }).join(''));

                    $select.prop('disabled', false).val(currentValue);
                    $select.on('change', function () {
                        $input.val($(this).val()).trigger('input').trigger('change');
                    });
                },
                function () {
                    $container.find('select').html('<option value="">Не удалось загрузить сценарии</option>');
                    $container.find('.clever-workflow-dp__hint').addClass('clever-workflow-dp__hint--error');
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
                mount();
                return true;
            },
            init: function () {
                return true;
            },
            bind_actions: function () {
                $(document)
                    .off('click.cleverWorkflowButtons')
                    .on('click.cleverWorkflowButtons', '.clever-workflow-card__button', function () {
                        runWorkflow($(this));
                    });

                return true;
            },
            settings: function () {
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
                $(document).off('click.cleverWorkflowButtons');
            }
        };

        return this;
    };

    return Widget;
});
