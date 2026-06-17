define(['jquery'], function ($) {
    var API_BASE = 'https://app.clevercrm.pro/api/amocrm/workflows/manual-buttons';
    var BLOCK_ID = 'clever-workflow-buttons';
    var BULK_MODAL_ID = 'clever-workflow-bulk-modal';
    var CAPTION_LOGO_URL = 'https://app.clevercrm.pro/amocrm/workflows/manual-buttons/images/logo_small.png?v=1.0.19';
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

        function injectStyles() {
            if ($('#' + BLOCK_ID + '-styles').length) {
                return;
            }

            $('head').append(
                '<style id="' + BLOCK_ID + '-styles">' +
                '.clever_workflow_buttons_caption{display:flex!important;align-items:center!important;gap:9px!important;min-height:50px!important;margin:0!important;padding:0 18px!important;background:#fff!important;box-sizing:border-box!important;color:#2f343b!important;font-family:inherit!important}' +
                '.clever-workflow-caption{display:flex;align-items:center;gap:9px;width:100%;margin:0;padding:0;background:#fff;color:#2f343b;font-family:inherit}' +
                '.clever-workflow-caption__logo{display:block;width:34px;height:34px;flex:0 0 34px;object-fit:contain}' +
                '.clever-workflow-caption__text{display:block;color:#2f343b;font-size:18px;font-weight:600;line-height:1.1;white-space:nowrap}' +
                '.clever-workflow-widget-body{padding:0!important;margin:0!important;background:#fff!important}' +
                '.clever-workflow-widget-shell{padding:0!important;margin:0!important;background:#fff!important}' +
                '.clever-workflow-card{display:block;width:100%;margin:0!important;padding:0!important;border:0;background:#fff;color:#181411;font-family:inherit;box-shadow:none}' +
                '.clever-workflow-card__head{display:none}' +
                '.clever-workflow-card__mark{display:none}' +
                '.clever-workflow-card__title{display:none}' +
                '.clever-workflow-card__list{display:flex;width:100%;flex-direction:column;gap:8px;border-top:0;background:#fff;padding:10px 14px 8px;box-sizing:border-box}' +
                '.clever-workflow-card__button{position:relative;display:block;width:100%;border:1px solid #d8dde3;border-radius:4px;background:#fff;color:#1f2933;min-height:34px;padding:7px 12px;font-size:13px;font-weight:400;line-height:1.3;cursor:pointer;text-align:center;box-shadow:0 2px 7px rgba(27,39,51,.10);transition:background .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease}' +
                '.clever-workflow-card__button:before{display:none}' +
                '.clever-workflow-card__button:hover{background:#f7f8fa;border-color:#b8c0ca;color:#181411;box-shadow:0 3px 10px rgba(27,39,51,.14)}' +
                '.clever-workflow-card__button:disabled{cursor:default;opacity:.65;transform:none}' +
                '.clever-workflow-card__empty,.clever-workflow-card__status{font-size:13px;line-height:1.45;color:#7a7068}' +
                '.clever-workflow-card__empty{padding:14px 18px}' +
                '.clever-workflow-card__status{margin:0;padding:8px 18px}' +
                '.clever-workflow-card__status:empty{display:none}' +
                '.clever-workflow-card__status--ok{color:#25884f}' +
                '.clever-workflow-card__status--error{color:#d1453b}' +
                '.clever-workflow-card__loader{display:inline-flex;align-items:center;gap:8px}' +
                '.clever-workflow-card__loader:before{content:"";width:12px;height:12px;border:2px solid #f5c3a2;border-top-color:#f17822;border-radius:999px;animation:cleverWorkflowSpin .8s linear infinite}' +
                '.clever-workflow-dp{margin-top:8px}' +
                '.clever-workflow-dp__select{width:100%;height:40px;border:1px solid #d7dadd;background:#fff;color:#313942;padding:0 10px;font-size:14px;outline:none}' +
                '.clever-workflow-dp__select:focus{border-color:#f17822}' +
                '.clever-workflow-dp__hint{margin-top:7px;color:#7a7068;font-size:12px;line-height:1.35}' +
                '.clever-workflow-dp__hint--error{color:#d1453b}' +
                '.clever-workflow-bulk{position:fixed;inset:0;z-index:999999;background:rgba(24,20,17,.38);display:flex;align-items:center;justify-content:center;padding:24px;font-family:inherit}' +
                '.clever-workflow-bulk__box{width:min(460px,100%);background:#fff;color:#181411;border:1px solid #eee0d5;box-shadow:0 18px 48px rgba(24,20,17,.18)}' +
                '.clever-workflow-bulk__head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid #eee7e1}' +
                '.clever-workflow-bulk__title{font-size:18px;font-weight:700;letter-spacing:-.01em}' +
                '.clever-workflow-bulk__close{border:0;background:transparent;color:#8a817a;font-size:28px;line-height:1;cursor:pointer;padding:0}' +
                '.clever-workflow-bulk__body{padding:18px 20px 20px}' +
                '.clever-workflow-bulk__meta{font-size:13px;color:#7a7068;margin-bottom:12px}' +
                '.clever-workflow-bulk__list{display:flex;flex-direction:column;border-top:1px solid #eee7e1}' +
                '.clever-workflow-bulk__scenario{position:relative;width:100%;border:0;border-bottom:1px solid #eee7e1;background:#fff;color:#181411;min-height:42px;padding:10px 12px;font-size:15px;font-weight:600;text-align:left;cursor:pointer}' +
                '.clever-workflow-bulk__scenario:before{display:none}' +
                '.clever-workflow-bulk__scenario:hover{background:#fff8f3;color:#c95c16}' +
                '.clever-workflow-bulk__scenario:disabled{opacity:.55;cursor:default}' +
                '.clever-workflow-bulk__message{font-size:14px;line-height:1.45;color:#7a7068}' +
                '.clever-workflow-bulk__message--error{color:#d1453b}' +
                '@keyframes cleverWorkflowSpin{to{transform:rotate(360deg)}}' +
                '</style>'
            );
        }

        function renderShell() {
            injectStyles();

            return [
                '<div id="' + BLOCK_ID + '" class="clever-workflow-card">',
                '<div class="clever-workflow-card__list">',
                '<div class="clever-workflow-card__empty clever-workflow-card__loader">Загрузка сценариев</div>',
                '</div>',
                '<div class="clever-workflow-card__status"></div>',
                '</div>'
            ].join('');
        }

        function renderCaption() {
            return [
                '<div class="clever-workflow-caption">',
                '<img class="clever-workflow-caption__logo" src="' + CAPTION_LOGO_URL + '" alt="">',
                '<span class="clever-workflow-caption__text">Сценарии</span>',
                '</div>'
            ].join('');
        }

        function normalizeCaption() {
            $('.clever_workflow_buttons_caption').html(renderCaption());
        }

        function decorateWidgetContainer() {
            var $block = $('#' + BLOCK_ID);

            if (!$block.length) {
                return;
            }

            $block.parent().addClass('clever-workflow-widget-body');
            $block.parent().parent().addClass('clever-workflow-widget-shell');
            normalizeCaption();
        }

        function mount() {
            var html = renderShell();

            if (typeof self.render_template === 'function') {
                self.render_template({
                    caption: {
                        class_name: 'clever_workflow_buttons_caption',
                        html: renderCaption()
                    },
                    body: '',
                    render: html
                });
            } else if (!$('#' + BLOCK_ID).length) {
                $('.card-widgets__widgets, .widgets__list, .linked-form__right, body').first().prepend(html);
            }

            decorateWidgetContainer();
            window.setTimeout(decorateWidgetContainer, 250);
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

        function selectedLeadIds() {
            var selected = {};
            var ids = [];

            try {
                selected = self.list_selected ? (self.list_selected().selected || {}) : {};
            } catch (e) {
                selected = {};
            }

            function pushId(value) {
                var id = parseInt(value, 10);

                if (id > 0 && ids.indexOf(id) === -1) {
                    ids.push(id);
                }
            }

            function collect(value, key, depth) {
                if (depth > 3 || value === null || typeof value === 'undefined') {
                    return;
                }

                if (typeof value !== 'object') {
                    if (/^\d+$/.test(String(key || '')) || /^(id|ID|entity_id|entityId)$/.test(String(key || ''))) {
                        pushId(value || key);
                    }

                    return;
                }

                pushId(value.id || value.ID || value.entity_id || value.entityId);

                if (/^\d+$/.test(String(key || ''))) {
                    pushId(key);
                }

                $.each(value, function (childKey, childValue) {
                    collect(childValue, childKey, depth + 1);
                });
            }

            collect(selected, '', 0);

            return ids;
        }

        function openBulkModal() {
            injectStyles();

            var leadIds = selectedLeadIds();
            var subdomain = accountSubdomain();

            $('#' + BULK_MODAL_ID).remove();
            $('body').append([
                '<div id="' + BULK_MODAL_ID + '" class="clever-workflow-bulk">',
                '<div class="clever-workflow-bulk__box">',
                '<div class="clever-workflow-bulk__head">',
                '<div class="clever-workflow-bulk__title">Сценарии</div>',
                '<button type="button" class="clever-workflow-bulk__close" aria-label="Закрыть">×</button>',
                '</div>',
                '<div class="clever-workflow-bulk__body">',
                '<div class="clever-workflow-bulk__meta">Выбрано сделок: ' + leadIds.length + '</div>',
                '<div class="clever-workflow-bulk__content">',
                '<div class="clever-workflow-bulk__message clever-workflow-card__loader">Загрузка сценариев</div>',
                '</div>',
                '</div>',
                '</div>',
                '</div>'
            ].join(''));

            $('#' + BULK_MODAL_ID).data('lead-ids', leadIds);

            if (!leadIds.length) {
                bulkMessage('Выберите сделки для запуска сценария.', true);
                return;
            }

            request(
                'GET',
                API_BASE,
                {
                    subdomain: subdomain
                },
                function (response) {
                    var workflows = response.workflows || [];

                    if (!response.ok) {
                        bulkMessage(response.message || 'Сценарии недоступны.', true);
                        return;
                    }

                    if (!workflows.length) {
                        bulkMessage('Нет включенных ручных сценариев.', false);
                        return;
                    }

                    $('#' + BULK_MODAL_ID + ' .clever-workflow-bulk__content').html(
                        '<div class="clever-workflow-bulk__list">' +
                        workflows.map(function (workflow) {
                            return '<button type="button" class="clever-workflow-bulk__scenario" data-workflow-id="' + workflow.id + '">' +
                                escapeHtml(workflow.name || ('Сценарий #' + workflow.id)) +
                                '</button>';
                        }).join('') +
                        '</div>'
                    );
                },
                function () {
                    bulkMessage('Не удалось загрузить сценарии. Проверьте подключение Clever.', true);
                }
            );
        }

        function bulkMessage(message, isError) {
            $('#' + BULK_MODAL_ID + ' .clever-workflow-bulk__content').html(
                '<div class="clever-workflow-bulk__message' + (isError ? ' clever-workflow-bulk__message--error' : '') + '">' +
                escapeHtml(message) +
                '</div>'
            );
        }

        function runBulkWorkflow($button) {
            var $modal = $('#' + BULK_MODAL_ID);
            var leadIds = $modal.data('lead-ids') || [];
            var workflowId = $button.data('workflow-id');
            var $buttons = $modal.find('.clever-workflow-bulk__scenario');

            if (!leadIds.length) {
                bulkMessage('Выберите сделки для запуска сценария.', true);
                return;
            }

            $buttons.prop('disabled', true);
            $button.text('Запускаю...');

            request(
                'POST',
                API_BASE + '/bulk-run',
                {
                    subdomain: accountSubdomain(),
                    workflow_id: workflowId,
                    lead_ids: leadIds
                },
                function (response) {
                    if (response.ok) {
                        $('#' + BULK_MODAL_ID).remove();
                    } else {
                        $buttons.prop('disabled', false);
                        bulkMessage(response.message || 'Не удалось запустить сценарий.', true);
                    }
                },
                function () {
                    $buttons.prop('disabled', false);
                    bulkMessage('Не удалось запустить сценарий.', true);
                }
            );
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
                if (isLeadCardArea()) {
                    mount();
                }

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
                    })
                    .on('click.cleverWorkflowButtons', '.clever-workflow-bulk__close', function () {
                        $('#' + BULK_MODAL_ID).remove();
                    })
                    .on('click.cleverWorkflowButtons', '#' + BULK_MODAL_ID, function (event) {
                        if (event.target === this) {
                            $('#' + BULK_MODAL_ID).remove();
                        }
                    })
                    .on('click.cleverWorkflowButtons', '.clever-workflow-bulk__scenario', function () {
                        runBulkWorkflow($(this));
                    });

                return true;
            },
            'leads.selected': function () {
                openBulkModal();
                return true;
            },
            'leads:selected': function () {
                openBulkModal();
                return true;
            },
            leads: {
                selected: function () {
                    openBulkModal();
                    return true;
                }
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
