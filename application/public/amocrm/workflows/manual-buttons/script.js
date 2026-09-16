define(['jquery'], function ($) {
    var API_BASE = 'https://app.clevercrm.pro/api/amocrm/workflows/manual-buttons';
    var BLOCK_ID = 'clever-workflow-buttons';
    var BULK_MODAL_ID = 'clever-workflow-bulk-modal';
    var VERSION = '1.0.43';
    var CAPTION_LOGO_FILE = 'images/clever_mini_logo.png?v=' + VERSION;
    var LOAD_RETRIES = 0;

    var Widget = function () {
        var self = this;
        var listLoading = false;
        var runPending = false;
        var lastListLoad = 0;
        var dpRetries = 0;
        var destroyed = false;

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
                '.clever-workflow-native-widget--hidden,.clever-workflow-caption--hidden,.clever-workflow-widget-body--hidden{display:none!important}' +
                '.clever_workflow_buttons_caption{display:flex!important;align-items:center!important;height:44px!important;min-height:44px!important;max-height:44px!important;margin:0!important;padding:0!important;background:#f5f5f5!important;box-sizing:border-box!important;color:#1f2933!important;font-family:inherit!important;overflow:hidden!important;border-bottom:1px solid #ededed!important}' +
                '.clever-workflow-caption{display:flex!important;align-items:center!important;justify-content:flex-start!important;gap:9px!important;width:100%!important;height:44px!important;min-height:44px!important;margin:0!important;padding:0 14px!important;background:#f5f5f5!important;color:#1f2933!important;font-family:inherit!important;box-sizing:border-box!important;cursor:pointer!important}' +
                '.clever-workflow-caption__icon{position:relative!important;display:none!important;align-items:center!important;justify-content:center!important;width:42px!important;height:42px!important;flex:0 0 42px!important;border-radius:13px!important;background:#f17822!important;color:#fff!important;box-shadow:0 8px 18px rgba(241,120,34,.25)!important}' +
                '.clever-workflow-caption__icon:before{content:""!important;position:absolute!important;inset:9px!important;border:2px solid rgba(255,255,255,.72)!important;border-radius:999px!important}' +
                '.clever-workflow-caption__icon:after{content:"▶"!important;position:relative!important;margin-left:2px!important;font-size:13px!important;line-height:1!important;color:#fff!important}' +
                '.clever-workflow-caption__logo{display:block!important;width:28px!important;height:28px!important;flex:0 0 28px!important;object-fit:contain!important;border-radius:999px!important}' +
                '.clever-workflow-caption__text{display:block!important;color:#f17822!important;font-size:14px!important;font-weight:400!important;line-height:1.2!important;letter-spacing:0!important;white-space:nowrap!important;text-align:left!important}' +
                '.clever-workflow-caption__chevron{display:block!important;margin-left:auto!important;flex:0 0 auto!important;color:#a0a7ae!important;font-size:14px!important}' +
                '.clever-workflow-native-widget .clever-workflow-native-header{display:flex!important;align-items:center!important;justify-content:flex-start!important;width:100%!important;height:44px!important;min-height:44px!important;max-height:44px!important;margin:0!important;padding:0!important;background:#f5f5f5!important;color:#1f2933!important;box-sizing:border-box!important;overflow:hidden!important;border-bottom:1px solid #ededed!important}' +
                '.clever-workflow-native-widget .clever-workflow-native-header>*:not(.clever-workflow-caption){display:none!important}' +
                '.clever-workflow-native-widget .clever-workflow-native-header .clever-workflow-caption{display:flex!important}' +
                '.clever-workflow-native-widget .clever-workflow-native-header>img{display:none!important}' +
                '.clever-workflow-widget-body{padding:0!important;margin:0!important;background:#fff!important}' +
                '.clever-workflow-widget-shell{padding:0!important;margin:0!important;background:#fff!important}' +
                '.clever-workflow-card{display:block;width:100%;margin:0!important;padding:0!important;border:0;background:#fff;color:#1f2933;font-family:inherit;box-shadow:none}' +
                '.clever-workflow-card__head{display:none}' +
                '.clever-workflow-card__mark{display:none}' +
                '.clever-workflow-card__title{display:none}' +
                '.clever-workflow-card__list{display:flex;width:100%;flex-direction:column;gap:7px;border-top:0;background:#fff;padding:8px 14px 10px;box-sizing:border-box}' +
                '.clever-workflow-card__button{position:relative;display:block;width:100%;border:1px solid #dfdfdf;border-radius:5px;background:#f8f8f8;color:#202226;min-height:34px;padding:5px 11px;font-size:14px;font-weight:400;line-height:1.3;cursor:pointer;text-align:left;box-shadow:0 1px 4px rgba(17,24,39,.07),inset 0 1px 0 rgba(255,255,255,.9);transition:background .15s ease,border-color .15s ease,color .15s ease,box-shadow .15s ease}' +
                '.clever-workflow-card__button:before{display:none}' +
                '.clever-workflow-card__button:hover{background:#fff6ef;border-color:#f17822;color:#202226;box-shadow:0 2px 9px rgba(241,120,34,.14),inset 0 1px 0 rgba(255,255,255,.95)}' +
                '.clever-workflow-card__button:disabled{cursor:default;opacity:.65;transform:none;color:#7c8591}' +
                '.clever-workflow-card__empty,.clever-workflow-card__status{font-size:14px;line-height:1.45;color:#7c8591}' +
                '.clever-workflow-card__empty{padding:14px 0}' +
                '.clever-workflow-card__status{margin:0;padding:0 14px 8px;background:#fff}' +
                '.clever-workflow-card__status:empty{display:none}' +
                '.clever-workflow-card__status--ok{color:#25884f}' +
                '.clever-workflow-card__status--error{color:#d1453b}' +
                '.clever-workflow-card__loader{display:inline-flex;align-items:center;gap:8px}' +
                '.clever-workflow-card__loader:before{content:"";width:12px;height:12px;border:2px solid #f5c3a2;border-top-color:#f17822;border-radius:999px;animation:cleverWorkflowSpin .8s linear infinite}' +
                '.clever-workflow-theme-dark .clever_workflow_buttons_caption,.clever-workflow-theme-dark .clever-workflow-caption,.clever-workflow-theme-dark .clever-workflow-native-widget .clever-workflow-native-header{height:44px!important;min-height:44px!important;max-height:44px!important;background:#142f40!important;color:#fff!important;border-bottom-color:rgba(255,255,255,.04)!important}' +
                '.clever-workflow-theme-dark .clever-workflow-caption{gap:9px!important;padding:0 14px!important}' +
                '.clever-workflow-theme-dark .clever-workflow-caption__logo{display:block!important;width:28px!important;height:28px!important;flex-basis:28px!important}' +
                '.clever-workflow-theme-dark .clever-workflow-caption__icon{display:none!important;width:34px!important;height:34px!important;flex-basis:34px!important;border-radius:11px!important;box-shadow:0 5px 12px rgba(241,120,34,.22)!important}' +
                '.clever-workflow-theme-dark .clever-workflow-caption__icon:before{inset:7px!important;border-width:2px!important}' +
                '.clever-workflow-theme-dark .clever-workflow-caption__icon:after{font-size:10px!important;margin-left:1px!important}' +
                '.clever-workflow-theme-dark .clever-workflow-caption__text{color:#f17822!important;font-size:14px!important;font-weight:400!important}' +
                '.clever-workflow-theme-dark .clever-workflow-caption__chevron{color:#8da4b3!important}' +
                '.clever-workflow-theme-dark .clever-workflow-widget-body,.clever-workflow-theme-dark .clever-workflow-widget-shell,.clever-workflow-theme-dark .clever-workflow-card,.clever-workflow-theme-dark .clever-workflow-card__list{background:#17384d!important;color:#f6fbff!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__list{gap:7px!important;padding:8px 14px 10px!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__button{min-height:34px!important;padding:5px 11px!important;border-color:#467999!important;border-radius:5px!important;background:#245b7b!important;color:#fff!important;font-size:14px!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.06),0 1px 4px rgba(0,0,0,.08)!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__button:hover{background:#2b6c91!important;border-color:#f17822!important;color:#fff!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.08),0 0 0 1px rgba(241,120,34,.18)!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__button:disabled{color:#d9e7ef!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__empty,.clever-workflow-theme-dark .clever-workflow-card__status{color:#b6cad6!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__status{background:#17384d!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__status--ok{color:#6ee7a8!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__status--error{color:#ffb199!important}' +
                '.clever-workflow-theme-dark .clever-workflow-card__loader:before{border-color:rgba(246,251,255,.22)!important;border-top-color:#f17822!important}' +
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
                '<div id="' + BLOCK_ID + '" class="clever-workflow-card" data-widget-version="' + VERSION + '">',
                '<div class="clever-workflow-card__list">',
                '<div class="clever-workflow-card__empty clever-workflow-card__loader">Загрузка потоков</div>',
                '</div>',
                '<div class="clever-workflow-card__status"></div>',
                '</div>'
            ].join('');
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

        function renderCaption() {
            return [
                '<div class="clever-workflow-caption" title="Потоки · ' + VERSION + '">',
                '<img class="clever-workflow-caption__logo" src="' + captionLogoUrl() + '" alt="">',
                '<span class="clever-workflow-caption__icon" aria-hidden="true"></span>',
                '<span class="clever-workflow-caption__text">Потоки</span>',
                '<span class="icon icon-v2-arrow-down clever-workflow-caption__chevron" aria-hidden="true"></span>',
                '</div>'
            ].join('');
        }

        function widgetRoots() {
            var selector = '.card-widgets__widget, .card-widgets__item, .widgets__item, .widgets-card__item, .widgets-card__widget, .widget-card';
            var $roots = $('.clever_workflow_buttons_caption').closest(selector);
            var $block = $('#' + BLOCK_ID);

            if ($block.length) {
                $roots = $roots.add($block.closest(selector));
            }

            return $roots;
        }

        function setWidgetVisibility(visible) {
            var $roots = widgetRoots();
            var hidden = !visible;

            $roots.toggleClass('clever-workflow-native-widget--hidden', hidden);

            if (!$roots.length) {
                $('.clever_workflow_buttons_caption').toggleClass('clever-workflow-caption--hidden', hidden);
                $('#' + BLOCK_ID).parent().toggleClass('clever-workflow-widget-body--hidden', hidden);
            }
        }

        function normalizeCaption() {
            applyThemeClass();

            $('.clever_workflow_buttons_caption')
                .addClass('clever-workflow-native-header')
                .html(renderCaption());

            normalizeNativeWidgetHeader();
        }

        function isDarkTheme() {
            var className = [
                document.documentElement.className || '',
                document.body.className || ''
            ].join(' ').toLowerCase();
            var bodyBackground = '';

            if (
                className.indexOf('theme-dark') !== -1 ||
                className.indexOf('dark-theme') !== -1 ||
                className.indexOf('dark') !== -1 ||
                className.indexOf('night') !== -1
            ) {
                return true;
            }

            try {
                bodyBackground = window.getComputedStyle(document.body).backgroundColor || '';
            } catch (e) {
                bodyBackground = '';
            }

            var match = bodyBackground.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);

            if (!match) {
                return false;
            }

            var brightness = (parseInt(match[1], 10) * 299 + parseInt(match[2], 10) * 587 + parseInt(match[3], 10) * 114) / 1000;

            return brightness < 90;
        }

        function applyThemeClass() {
            $('html')
                .toggleClass('clever-workflow-theme-dark', isDarkTheme())
                .toggleClass('clever-workflow-theme-light', !isDarkTheme());
        }

        function normalizeNativeWidgetHeader() {
            var $block = $('#' + BLOCK_ID);
            var $roots = widgetRoots();

            $roots.each(function () {
                var $root = $(this);
                var $header = $root.find('.clever_workflow_buttons_caption').first();

                if (!$header.length) {
                    $header = $root.children().filter(function () {
                        var $child = $(this);

                        return !$child.find('#' + BLOCK_ID).length && !$child.is('#' + BLOCK_ID);
                    }).first();
                }

                if (!$header.length) {
                    return;
                }

                $root.addClass('clever-workflow-native-widget');
                $header
                    .addClass('clever-workflow-native-header')
                    .html(renderCaption());
            });
        }

        function decorateWidgetContainer() {
            var $block = $('#' + BLOCK_ID);

            if (!$block.length) {
                return;
            }

            $block.parent().addClass('clever-workflow-widget-body');
            $block.parent().parent().addClass('clever-workflow-widget-shell');
            applyThemeClass();
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
            setWidgetVisibility(false);
            window.setTimeout(decorateWidgetContainer, 250);
            window.setTimeout(decorateWidgetContainer, 700);
            window.setTimeout(decorateWidgetContainer, 1400);
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
                payload = $.extend({}, payload, {widget_version: VERSION, _ts: Date.now()});
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
            if (destroyed || listLoading || runPending || !isLeadCardArea()) return;
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
                setWidgetVisibility(false);
                $list.html('<div class="clever-workflow-card__empty">Откройте карточку сделки.</div>');
                return;
            }

            listLoading = true;
            lastListLoad = Date.now();
            request(
                'GET',
                API_BASE,
                {
                    subdomain: subdomain,
                    lead_id: leadId,
                    source: 'amo-button'
                },
                function (response) {
                    listLoading = false;
                    if (destroyed || currentLeadId() !== leadId) return;
                    var workflows = response.workflows || [];

                    if (!response.ok) {
                        setWidgetVisibility(true);
                        $list.html('<div class="clever-workflow-card__empty">' + escapeHtml(response.message || 'Потоки недоступны.') + '</div>');
                        return;
                    }

                    if (!workflows.length) {
                        setWidgetVisibility(false);
                        $list.empty();
                        $status.text('');
                        return;
                    }

                    setWidgetVisibility(true);

                    $list.html(workflows.map(function (workflow) {
                        return '<button type="button" class="clever-workflow-card__button" data-workflow-id="' + workflow.id + '">' +
                            escapeHtml(workflow.name || ('Поток #' + workflow.id)) +
                            '</button>';
                    }).join(''));

                    $status.text('');
                },
                function () {
                    listLoading = false;
                    if (destroyed || currentLeadId() !== leadId) return;
                    setWidgetVisibility(true);
                    $list.html('<div class="clever-workflow-card__empty">Не удалось загрузить потоки. Проверьте подключение Clever.</div>');
                }
            );
        }

        function runWorkflow($button) {
            if (runPending) return;
            runPending = true;
            var workflowId = $button.data('workflow-id');
            var leadId = currentLeadId();
            var $block = $('#' + BLOCK_ID);
            var $buttons = $block.find('.clever-workflow-card__button');
            var $status = $block.find('.clever-workflow-card__status');
            var originalText = $button.text();
            function finish() {
                runPending = false;
                $button.text(originalText);
                $buttons.prop('disabled', false);
            }

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
                    lead_name: currentLeadName(),
                    source: 'amo-button'
                },
                function (response) {
                    finish();
                    if (response.ok) {
                        $status.removeClass('clever-workflow-card__status--ok clever-workflow-card__status--error').text('');
                    } else {
                        $status.addClass('clever-workflow-card__status--error').text(response.message || 'Не удалось запустить поток.');
                    }
                },
                function () {
                    finish();
                    $status.addClass('clever-workflow-card__status--error').text('Не удалось запустить поток.');
                }
            );

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

        function captureListSelection(leadIds) {
            var ids = leadIds || [];
            var $checked = $('input[type="checkbox"]:checked')
                .not('#' + BULK_MODAL_ID + ' input[type="checkbox"]');
            var $rows = $();

            $checked.each(function () {
                $rows = $rows.add($(this).closest('tr, .list-row, .list__body-row, .pipeline_leads__item, .entity-row'));
            });

            $.each(ids, function (_, id) {
                var selector = [
                    '[data-id="' + id + '"]',
                    '[data-entity-id="' + id + '"]',
                    '[data-lead-id="' + id + '"]',
                    'a[href*="/leads/detail/' + id + '"]'
                ].join(',');

                $(selector).each(function () {
                    $rows = $rows.add($(this).closest('tr, .list-row, .list__body-row, .pipeline_leads__item, .entity-row'));
                });
            });

            return {
                ids: ids.slice(0),
                checkboxes: $checked.toArray(),
                rows: $rows.toArray()
            };
        }

        function markSelectedRow($row) {
            if (!$row.length) {
                return;
            }

            $row.addClass('is-checked checked selected list-row_checked list-row_selected');
            $row.find('input[type="checkbox"]').prop('checked', true).attr('checked', 'checked');
            $row.find('.control-checkbox, .control--checkbox, .checkboxes_dropdown__item, .list-row__cell-template')
                .addClass('is-checked checked selected');
        }

        function restoreListSelection(snapshot) {
            if (!snapshot) {
                return;
            }

            $.each(snapshot.checkboxes || [], function (_, checkbox) {
                var $checkbox = $(checkbox);

                $checkbox.prop('checked', true).attr('checked', 'checked');
                markSelectedRow($checkbox.closest('tr, .list-row, .list__body-row, .pipeline_leads__item, .entity-row'));
            });

            $.each(snapshot.rows || [], function (_, row) {
                markSelectedRow($(row));
            });

            $.each(snapshot.ids || [], function (_, id) {
                var selector = [
                    '[data-id="' + id + '"]',
                    '[data-entity-id="' + id + '"]',
                    '[data-lead-id="' + id + '"]',
                    'a[href*="/leads/detail/' + id + '"]'
                ].join(',');

                $(selector).each(function () {
                    markSelectedRow($(this).closest('tr, .list-row, .list__body-row, .pipeline_leads__item, .entity-row'));
                });
            });
        }

        function restoreListSelectionSoon(snapshot) {
            restoreListSelection(snapshot);
            window.setTimeout(function () {
                restoreListSelection(snapshot);
            }, 60);
            window.setTimeout(function () {
                restoreListSelection(snapshot);
            }, 180);
            window.setTimeout(function () {
                restoreListSelection(snapshot);
            }, 420);
        }

        function openBulkModal() {
            injectStyles();

            var leadIds = selectedLeadIds();
            var subdomain = accountSubdomain();
            var selectionSnapshot = captureListSelection(leadIds);

            $('#' + BULK_MODAL_ID).remove();
            $('body').append([
                '<div id="' + BULK_MODAL_ID + '" class="clever-workflow-bulk">',
                '<div class="clever-workflow-bulk__box">',
                '<div class="clever-workflow-bulk__head">',
                '<div class="clever-workflow-bulk__title">Потоки</div>',
                '<button type="button" class="clever-workflow-bulk__close" aria-label="Закрыть">×</button>',
                '</div>',
                '<div class="clever-workflow-bulk__body">',
                '<div class="clever-workflow-bulk__meta">Выбрано сделок: ' + leadIds.length + '</div>',
                '<div class="clever-workflow-bulk__content">',
                '<div class="clever-workflow-bulk__message clever-workflow-card__loader">Загрузка потоков</div>',
                '</div>',
                '</div>',
                '</div>',
                '</div>'
            ].join(''));

            $('#' + BULK_MODAL_ID).data('lead-ids', leadIds);
            restoreListSelectionSoon(selectionSnapshot);

            if (!leadIds.length) {
                bulkMessage('Выберите сделки для запуска потока.', true);
                return;
            }

            request(
                'GET',
                API_BASE,
                {
                    subdomain: subdomain,
                    source: 'amo-button'
                },
                function (response) {
                    var workflows = response.workflows || [];

                    if (!response.ok) {
                        bulkMessage(response.message || 'Потоки недоступны.', true);
                        return;
                    }

                    if (!workflows.length) {
                        bulkMessage('Нет включенных потоков с запуском кнопкой.', false);
                        return;
                    }

                    $('#' + BULK_MODAL_ID + ' .clever-workflow-bulk__content').html(
                        '<div class="clever-workflow-bulk__list">' +
                        workflows.map(function (workflow) {
                            return '<button type="button" class="clever-workflow-bulk__scenario" data-workflow-id="' + workflow.id + '">' +
                                escapeHtml(workflow.name || ('Поток #' + workflow.id)) +
                                '</button>';
                        }).join('') +
                        '</div>'
                    );
                },
                function () {
                    bulkMessage('Не удалось загрузить потоки. Проверьте подключение Clever.', true);
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
                bulkMessage('Выберите сделки для запуска потока.', true);
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
                    lead_ids: leadIds,
                    source: 'amo-button'
                },
                function (response) {
                    if (response.ok) {
                        $('#' + BULK_MODAL_ID).remove();
                    } else {
                        $buttons.prop('disabled', false);
                        bulkMessage(response.message || 'Не удалось запустить поток.', true);
                    }
                },
                function () {
                    $buttons.prop('disabled', false);
                    bulkMessage('Не удалось запустить поток.', true);
                }
            );
        }

        function renderDpSettings() {
            if (destroyed) return;
            injectStyles();

            var $input = $('input[name="workflow_id"], input[name$="[workflow_id]"]').first();

            if (!$input.length) {
                if (dpRetries++ < 20) window.setTimeout(renderDpSettings, 150);
                return;
            }

            if ($('#clever-workflow-dp-settings').length) {
                return;
            }

            var currentValue = String($input.val() || '');
            var $field = $input.closest('.widget_settings_block__input_field, .control-wrapper, .linked-form__field, .js-widget-settings__field');
            var $container = $('<div id="clever-workflow-dp-settings" class="clever-workflow-dp">' +
                '<select class="clever-workflow-dp__select" disabled><option>Загрузка потоков...</option></select>' +
                '<div class="clever-workflow-dp__hint">Выберите поток с запуском Digital Pipeline. Он выполнится при срабатывании действия в воронке.</div>' +
                '<button type="button" class="clever-workflow-dp__refresh" style="margin-top:8px;background:transparent;border:0;color:#137cbd;cursor:pointer">Обновить список</button>' +
                '</div>');

            $input.attr('type', 'hidden');
            $container.find('.clever-workflow-dp__refresh').on('click', function () {
                $container.remove();
                dpRetries = 0;
                renderDpSettings();
            });

            if ($field.length) {
                $field.after($container);
            } else {
                $input.after($container);
            }

            request(
                'GET',
                API_BASE,
                {
                    subdomain: accountSubdomain(),
                    source: 'digital-pipeline'
                },
                function (response) {
                    var workflows = response.workflows || [];
                    var $select = $container.find('select');

                    if (!response.ok) {
                        $select.html('<option value="">' + escapeHtml(response.message || 'Потоки недоступны') + '</option>');
                        $container.find('.clever-workflow-dp__hint').addClass('clever-workflow-dp__hint--error');
                        return;
                    }

                    if (!workflows.length) {
                        $select.html('<option value="">Нет включенных потоков Digital Pipeline</option>');
                        return;
                    }

                    $select.html('<option value="">Выберите поток</option>' + workflows.map(function (workflow) {
                        return '<option value="' + workflow.id + '">' + escapeHtml(workflow.name || ('Поток #' + workflow.id)) + '</option>';
                    }).join(''));

                    $select.prop('disabled', false).val(currentValue);
                    $select.on('change', function () {
                        $input.val($(this).val()).trigger('input').trigger('change');
                    });
                },
                function () {
                    $container.find('select').html('<option value="">Не удалось загрузить потоки</option>');
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
                destroyed = false;
                LOAD_RETRIES = 0;
                if (isLeadCardArea()) {
                    mount();
                }
                return true;
            },
            init: function () {
                return true;
            },
            bind_actions: function () {
                $(window).off('focus.cleverWorkflowButtons').on('focus.cleverWorkflowButtons', function () {
                    if (Date.now() - lastListLoad > 2000) loadButtons();
                });
                $(document).off('visibilitychange.cleverWorkflowButtons').on('visibilitychange.cleverWorkflowButtons', function () {
                    if (!document.hidden && Date.now() - lastListLoad > 2000) loadButtons();
                });
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
                dpRetries = 0;
                destroyed = false;
                renderDpSettings();
                return true;
            },
            onSave: function () {
                return true;
            },
            destroy: function () {
                destroyed = true;
                $(document).off('.cleverWorkflowButtons');
                $(window).off('.cleverWorkflowButtons');
            }
        };

        return this;
    };

    return Widget;
});
