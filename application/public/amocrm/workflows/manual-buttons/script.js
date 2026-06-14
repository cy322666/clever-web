define(['jquery'], function ($) {
    var API_BASE = 'https://app.clevercrm.pro/api/amocrm/workflows/manual-buttons';
    var BLOCK_ID = 'clever-workflow-buttons';

    var Widget = function () {
        var self = this;

        function accountSubdomain() {
            var account = {};

            try {
                account = AMOCRM.constant('account') || {};
            } catch (e) {
                account = {};
            }

            return account.subdomain || account.account_subdomain || '';
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
                '.clever-workflow-card{padding:14px;border:1px solid #e8ded5;background:#fffaf5;color:#181411;font-family:inherit}' +
                '.clever-workflow-card__title{font-size:15px;font-weight:600;margin:0 0 10px;color:#181411}' +
                '.clever-workflow-card__list{display:flex;flex-direction:column;gap:8px}' +
                '.clever-workflow-card__button{width:100%;border:1px solid #f17822;background:#f17822;color:#fff;min-height:38px;padding:8px 12px;font-size:14px;font-weight:600;cursor:pointer;text-align:left}' +
                '.clever-workflow-card__button:hover{background:#dd6716;border-color:#dd6716}' +
                '.clever-workflow-card__button:disabled{cursor:default;opacity:.65}' +
                '.clever-workflow-card__empty,.clever-workflow-card__status{font-size:13px;line-height:1.4;color:#7a7068}' +
                '.clever-workflow-card__status{margin-top:10px}' +
                '.clever-workflow-card__status--ok{color:#26884a}' +
                '.clever-workflow-card__status--error{color:#d14343}' +
                '</style>'
            );
        }

        function renderShell() {
            injectStyles();

            return [
                '<div id="' + BLOCK_ID + '" class="clever-workflow-card">',
                '<div class="clever-workflow-card__title">Сценарии</div>',
                '<div class="clever-workflow-card__list">',
                '<div class="clever-workflow-card__empty">Загрузка...</div>',
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

            loadButtons();
        }

        function request(method, url, data, onSuccess, onError) {
            var payload = data || {};

            if (method === 'GET') {
                var query = $.param(payload);
                url += query ? '?' + query : '';
                payload = {};
            }

            if (typeof self.crm_post === 'function') {
                self.crm_post(
                    url,
                    payload,
                    function (response) {
                        onSuccess(parseResponse(response));
                    },
                    'json',
                    function (error) {
                        onError(error);
                    }
                );

                return;
            }

            $.ajax({
                url: url,
                method: method,
                data: method === 'GET' ? payload : JSON.stringify(payload),
                contentType: 'application/json',
                dataType: 'json'
            }).done(onSuccess).fail(onError);
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

        function loadButtons() {
            var leadId = currentLeadId();
            var subdomain = accountSubdomain();
            var $block = $('#' + BLOCK_ID);
            var $list = $block.find('.clever-workflow-card__list');
            var $status = $block.find('.clever-workflow-card__status');

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
                    $list.html('<div class="clever-workflow-card__empty">Не удалось загрузить сценарии.</div>');
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
                        $status.addClass('clever-workflow-card__status--ok').text('Сценарий запущен.');
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
