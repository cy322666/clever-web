define(['jquery'], function ($) {
    'use strict';

    var PIPELINES = [
        {
            code: 'main',
            name: 'Основная',
            sort: 10,
            is_main: true,
            success: 'Успешно реализовано',
            failure: 'Закрыто и не реализовано',
            statuses: [
                status('new', 'Новый лид', 20, '#fffeb2'),
                status('working', 'Взят в работу', 30, '#fffd7f'),
                status('contacted', 'Контакт установлен', 40, '#ebffb1'),
                status('qualified', 'Квалифицирован', 50, '#ccff66'),
                status('booked', 'Клиент записан', 60, '#99ccff'),
                status('confirmed', 'Запись подтверждена', 70, '#99ccff'),
                status('visited', 'Клиент пришел', 80, '#99ccff')
            ]
        },
        {
            code: 'reactivation',
            name: 'Реактивация',
            sort: 20,
            is_main: false,
            success: 'Отправлен в основную',
            failure: 'Закрыто и не реализовано',
            statuses: [
                status('new', 'Новый лид', 20, '#fffeb2'),
                status('working', 'Взят в работу', 30, '#fffd7f'),
                status('unreached', 'Недозвонились', 40, '#ffdbdb'),
                status('qualified', 'Квалифицирован', 50, '#ccff66')
            ]
        },
        {
            code: 'subscriptions',
            name: 'Абонементы',
            sort: 30,
            is_main: false,
            success: 'Абонемент куплен',
            failure: 'Абонемент не куплен',
            statuses: [
                status('sold', 'Абонемент куплен', 20, '#fffeb2'),
                status('started', 'Начал посещать', 30, '#99ccff'),
                status('upsell', 'Ждет допродажи', 40, '#fffd7f')
            ]
        }
    ];
    var INTEGRATION_TITLES = {
        yclients: 'YClients',
        sqns: 'SQNS',
        vetmanager: 'Ветменеджер',
        distribution: 'Распределение',
        tilda: 'Тильда',
        max: 'MAX',
        telegram: 'Telegram',
        whatsapp: 'WhatsApp',
        vk: 'ВКонтакте',
        avito: 'Авито',
        instagram: 'Instagram Direct'
    };

    function status(code, name, sort, color) {
        return {
            code: code,
            name: name,
            sort: sort,
            color: color
        };
    }

    function request(method, path, payload) {
        return new Promise(function (resolve, reject) {
            var options = {
                url: path,
                method: method,
                contentType: 'application/json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                success: resolve,
                error: function (xhr) {
                    var response = xhr && xhr.responseJSON;
                    var message = response && (response.detail || response.title);

                    reject(new Error(message || ('amoCRM API: ' + method + ' ' + path + ' (' + (xhr.status || 0) + ')')));
                }
            };

            if (payload !== undefined) {
                options.data = JSON.stringify(payload);
            }

            $.ajax(options);
        });
    }

    function embedded(response, key) {
        return response && response._embedded && response._embedded[key]
            ? response._embedded[key]
            : [];
    }

    function sequence(items, handler) {
        return items.reduce(function (promise, item, index) {
            return promise.then(function (results) {
                return handler(item, index).then(function (result) {
                    results.push(result);
                    return results;
                });
            });
        }, Promise.resolve([]));
    }

    function normalizeWizardData(allStepsData) {
        var result = {
            organization_format: 'single',
            integrations: []
        };

        function visit(value) {
            if (!value) {
                return;
            }

            if (Array.isArray(value)) {
                value.forEach(visit);
                return;
            }

            if (typeof value !== 'object') {
                return;
            }

            if (value.organization_format) {
                result.organization_format = String(value.organization_format);
            }

            if (Array.isArray(value.integrations)) {
                result.integrations = value.integrations.map(String);
            }

            Object.keys(value).forEach(function (key) {
                if (key !== 'integrations') {
                    visit(value[key]);
                }
            });
        }

        visit(allStepsData);

        return result;
    }

    function pipelinePayload(definition) {
        return {
            name: definition.name,
            sort: definition.sort,
            is_main: definition.is_main,
            is_unsorted_on: false,
            request_id: definition.code,
            _embedded: {
                statuses: definition.statuses.map(function (item) {
                    return {
                        name: item.name,
                        sort: item.sort,
                        color: item.color,
                        request_id: item.code
                    };
                }).concat([
                    { id: 142, name: definition.success },
                    { id: 143, name: definition.failure }
                ])
            }
        };
    }

    function refreshPipeline(pipelineId) {
        return request('GET', '/api/v4/leads/pipelines/' + pipelineId);
    }

    function patchSystemStatuses(pipeline, definition) {
        var updates = [
            { id: 142, name: definition.success, sort: 10000, color: '#CCFF66' },
            { id: 143, name: definition.failure, sort: 11000, color: '#D5D8DB' }
        ];

        return sequence(updates, function (update) {
            return request(
                'PATCH',
                '/api/v4/leads/pipelines/' + pipeline.id + '/statuses/' + update.id,
                update
            );
        });
    }

    function ensureStatuses(pipeline, definition, resetExisting) {
        var current = (pipeline._embedded && pipeline._embedded.statuses) || [];
        var editable = current.filter(function (item) {
            return item.is_editable && item.id !== 142 && item.id !== 143;
        });
        var remove = resetExisting ? editable : [];

        return sequence(remove, function (item) {
            return request('DELETE', '/api/v4/leads/pipelines/' + pipeline.id + '/statuses/' + item.id);
        }).then(function () {
            return refreshPipeline(pipeline.id);
        }).then(function (fresh) {
            var names = ((fresh._embedded && fresh._embedded.statuses) || []).map(function (item) {
                return item.name;
            });
            var missing = definition.statuses.filter(function (item) {
                return names.indexOf(item.name) === -1;
            }).map(function (item) {
                return {
                    name: item.name,
                    sort: item.sort,
                    color: item.color,
                    request_id: item.code
                };
            });

            return missing.length
                ? request('POST', '/api/v4/leads/pipelines/' + pipeline.id + '/statuses', missing)
                : null;
        }).then(function () {
            return patchSystemStatuses(pipeline, definition);
        }).then(function () {
            return refreshPipeline(pipeline.id);
        });
    }

    function ensureMainPipeline(existing, definition) {
        var currentName = existing.name;

        return request('PATCH', '/api/v4/leads/pipelines/' + existing.id, {
            name: definition.name,
            sort: definition.sort,
            is_main: true,
            is_unsorted_on: false
        }).then(function (updated) {
            return ensureStatuses(updated, definition, currentName !== definition.name);
        });
    }

    function ensureSecondaryPipeline(existing, definition) {
        if (existing) {
            return request('PATCH', '/api/v4/leads/pipelines/' + existing.id, {
                name: definition.name,
                sort: definition.sort,
                is_main: false,
                is_unsorted_on: false
            }).then(function (updated) {
                return ensureStatuses(updated, definition, false);
            });
        }

        return request('POST', '/api/v4/leads/pipelines', [pipelinePayload(definition)])
            .then(function (response) {
                return embedded(response, 'pipelines')[0];
            });
    }

    function ensurePipelines() {
        return request('GET', '/api/v4/leads/pipelines?limit=50').then(function (response) {
            var existing = embedded(response, 'pipelines');
            var main = existing.filter(function (item) { return item.is_main; })[0];

            if (!main) {
                throw new Error('В аккаунте не найдена основная воронка');
            }

            return ensureMainPipeline(main, PIPELINES[0]).then(function (mainPipeline) {
                return sequence(PIPELINES.slice(1), function (definition) {
                    var found = existing.filter(function (item) {
                        return item.name === definition.name;
                    })[0];

                    return ensureSecondaryPipeline(found, definition);
                }).then(function (secondary) {
                    return [mainPipeline].concat(secondary);
                });
            });
        });
    }

    function findPipeline(pipelines, name) {
        return pipelines.filter(function (pipeline) { return pipeline.name === name; })[0];
    }

    function findStatus(pipeline, name, systemId) {
        var statuses = (pipeline._embedded && pipeline._embedded.statuses) || [];

        if (systemId) {
            return statuses.filter(function (item) { return item.id === systemId; })[0];
        }

        return statuses.filter(function (item) {
            return item.name === name && item.id !== 142 && item.id !== 143;
        })[0];
    }

    function requiredStatuses(pipeline, names) {
        return names.map(function (name) {
            var item = findStatus(pipeline, name);

            return item ? { pipeline_id: pipeline.id, status_id: item.id } : null;
        }).filter(Boolean);
    }

    function ensureIntegrationGroup() {
        return request('GET', '/api/v4/leads/custom_fields/groups').then(function (response) {
            var groups = embedded(response, 'custom_field_groups');
            var existing = groups.filter(function (group) {
                return group.name === 'Система записей';
            })[0];

            if (existing) {
                return existing;
            }

            return request('POST', '/api/v4/leads/custom_fields/groups', [{
                name: 'Система записей',
                sort: 10,
                request_id: 'booking_system'
            }]).then(function (created) {
                return embedded(created, 'custom_field_groups')[0];
            });
        });
    }

    function fieldDefinitions(pipelines, group, options) {
        var main = findPipeline(pipelines, 'Основная');
        var reactivation = findPipeline(pipelines, 'Реактивация');
        var subscriptions = findPipeline(pipelines, 'Абонементы');
        var qualified = requiredStatuses(main, [
            'Квалифицирован', 'Клиент записан', 'Запись подтверждена', 'Клиент пришел'
        ]).concat([{ pipeline_id: main.id, status_id: 142 }]);
        var booked = requiredStatuses(main, [
            'Клиент записан', 'Запись подтверждена', 'Клиент пришел'
        ]).concat([{ pipeline_id: main.id, status_id: 142 }]);
        var reactQualified = requiredStatuses(reactivation, ['Квалифицирован'])
            .concat([{ pipeline_id: reactivation.id, status_id: 142 }]);
        var rejection = [
            { pipeline_id: main.id, status_id: 143 },
            { pipeline_id: reactivation.id, status_id: 143 },
            { pipeline_id: subscriptions.id, status_id: 143 }
        ];

        return [
            selectField('Тип клиента', 100, 'default', qualified.concat(reactQualified), ['Первичный', 'Повторный']),
            textField('Интересующие услуги', 110, 'default', qualified.concat(reactQualified)),
            textField('Услуга', 120, 'default'),
            dateTimeField('Дата и время записи', 130, 'default', booked),
            textareaField('Комментарий', 140, 'default'),
            textField('Филиал', 150, 'default', options.organization_format === 'network' ? booked : []),
            textField('Специалист/Врач', 160, 'default'),
            textField('Рекламный источник', 170, 'default'),
            selectField('Причина отказа', 180, 'default', rejection, [
                'Не дозвонились', 'Неактуально', 'Цена', 'Выбрали другую организацию', 'Другое'
            ]),
            textareaField('Подробная причина отказа', 190, 'default'),
            apiTextField('ID записи', 100, group.id),
            apiTextField('ID компании', 110, group.id),
            apiTextField('Создатель записи', 120, group.id),
            apiTextField('Отдел создателя', 130, group.id),
            apiTextField('Роль создателя', 140, group.id),
            apiDateTimeField('Дата создания записи', 150, group.id),
            apiTextField('Источник записи', 160, group.id)
        ];
    }

    function baseField(name, type, sort, groupId, required) {
        return {
            name: name,
            type: type,
            sort: sort,
            group_id: groupId,
            required_statuses: required || [],
            is_api_only: false,
            request_id: name
        };
    }

    function textField(name, sort, groupId, required) {
        return baseField(name, 'text', sort, groupId, required);
    }

    function textareaField(name, sort, groupId, required) {
        return baseField(name, 'textarea', sort, groupId, required);
    }

    function dateTimeField(name, sort, groupId, required) {
        return baseField(name, 'date_time', sort, groupId, required);
    }

    function selectField(name, sort, groupId, required, values) {
        var field = baseField(name, 'select', sort, groupId, required);
        field.enums = values.map(function (value, index) {
            return { value: value, sort: index + 1 };
        });
        return field;
    }

    function apiTextField(name, sort, groupId) {
        var field = textField(name, sort, groupId);
        field.is_api_only = true;
        return field;
    }

    function apiDateTimeField(name, sort, groupId) {
        var field = dateTimeField(name, sort, groupId);
        field.is_api_only = true;
        return field;
    }

    function patchField(existing, definition) {
        return request('PATCH', '/api/v4/leads/custom_fields/' + existing.id, {
            name: definition.name,
            sort: definition.sort,
            group_id: definition.group_id,
            required_statuses: definition.required_statuses,
            is_api_only: definition.is_api_only
        });
    }

    function ensureFields(pipelines, group, options) {
        var definitions = fieldDefinitions(pipelines, group, options);

        return request('GET', '/api/v4/leads/custom_fields?limit=250').then(function (response) {
            var existing = embedded(response, 'custom_fields');
            var missing = definitions.filter(function (definition) {
                return !existing.some(function (field) { return field.name === definition.name; });
            });
            var updates = definitions.map(function (definition) {
                var field = existing.filter(function (item) { return item.name === definition.name; })[0];
                return field ? { existing: field, definition: definition } : null;
            }).filter(Boolean);

            return sequence(updates, function (item) {
                return patchField(item.existing, item.definition);
            }).then(function () {
                return missing.length
                    ? request('POST', '/api/v4/leads/custom_fields', missing)
                    : null;
            });
        }).then(function () {
            return request('GET', '/api/v4/leads/custom_fields?limit=250');
        }).then(function (response) {
            return embedded(response, 'custom_fields');
        });
    }

    function installSelectedWidgets(selected, config) {
        var codes = config.widget_codes || {};
        var installed = [];
        var pending = [];

        return sequence(selected, function (key) {
            var code = codes[key];

            if (!code) {
                pending.push(key);
                return Promise.resolve(null);
            }

            return request('POST', '/api/v4/widgets/' + encodeURIComponent(code), {})
                .then(function (result) {
                    installed.push(key);
                    return result;
                });
        }).then(function () {
            return { installed: installed, pending: pending };
        });
    }

    function fieldByName(fields, name) {
        return fields.filter(function (field) { return field.name === name; })[0];
    }

    function enumId(field, value) {
        var enums = field && (field.enums || (field._embedded && field._embedded.enums)) || [];
        var item = enums.filter(function (entry) { return entry.value === value; })[0];
        return item ? item.id : null;
    }

    function addTextValue(values, fields, name, value) {
        var field = fieldByName(fields, name);

        if (field && value) {
            values.push({ field_id: field.id, values: [{ value: value }] });
        }
    }

    function sampleDetails(definition, options) {
        var common = {
            type: definition.code === 'main' ? 'Первичный' : 'Повторный',
            branch: options.organization_format === 'network' ? 'Центральный филиал' : '',
            specialist: 'Иванова Мария',
            source: definition.code === 'reactivation' ? 'База клиентов' : 'Сайт'
        };

        if (definition.code === 'reactivation') {
            return Object.assign(common, {
                name: 'ДЕМО · Возврат клиента после отмены',
                price: 0,
                service: 'Повторный приём',
                comment: 'Клиент отменил прошлую запись. Связаться и предложить новое время.',
                note: [
                    'Назначение воронки: возврат клиентов после отмены, неявки или долгого перерыва.',
                    'Сюда можно отправлять клиентов автоматически либо загружать нужный сегмент вручную.',
                    'После согласия клиента создайте новую запись в воронке «Основная».'
                ]
            });
        }

        if (definition.code === 'subscriptions') {
            return Object.assign(common, {
                name: 'ДЕМО · Абонемент на курс',
                price: 12000,
                service: 'Абонемент на курс из 10 посещений',
                comment: 'Контролировать начало посещений и предложить продление до окончания курса.',
                note: [
                    'Назначение воронки: продажа, использование и продление абонементов.',
                    'Модель учёта: одна сделка — один абонемент.',
                    'После начала посещений переведите сделку в этап «Начал посещать», затем — «Ждет допродажи».'
                ]
            });
        }

        return Object.assign(common, {
            name: 'ДЕМО · Запись на первичный приём',
            price: 4200,
            service: 'Первичная консультация',
            comment: 'Клиент хочет записаться на первичную консультацию.',
            note: [
                'Назначение воронки: обработка новых обращений и запись клиента на услугу.',
                'Модель учёта: одна сделка — одна запись клиента.',
                'В сделке храните услугу, дату и время, филиал и специалиста. Новая самостоятельная запись — новая сделка.'
            ]
        });
    }

    function sampleCustomFields(fields, details, dateOffsetDays) {
        var values = [];
        var type = fieldByName(fields, 'Тип клиента');
        var date = fieldByName(fields, 'Дата и время записи');

        addTextValue(values, fields, 'Интересующие услуги', details.service);
        addTextValue(values, fields, 'Услуга', details.service);
        addTextValue(values, fields, 'Комментарий', details.comment);
        addTextValue(values, fields, 'Филиал', details.branch);
        addTextValue(values, fields, 'Специалист/Врач', details.specialist);
        addTextValue(values, fields, 'Рекламный источник', details.source);

        if (type && enumId(type, details.type)) {
            values.push({ field_id: type.id, values: [{ enum_id: enumId(type, details.type) }] });
        }

        if (date) {
            values.push({
                field_id: date.id,
                values: [{ value: Math.floor(Date.now() / 1000) + (dateOffsetDays * 86400) }]
            });
        }

        return values;
    }

    function createSample(definition, pipeline, fields, options, index) {
        var details = sampleDetails(definition, options);
        var first = (pipeline._embedded && pipeline._embedded.statuses || [])
            .filter(function (item) { return item.is_editable && item.id !== 142 && item.id !== 143; })
            .sort(function (a, b) { return a.sort - b.sort; })[0];
        var integrations = options.integrations.length
            ? options.integrations.map(function (code) { return INTEGRATION_TITLES[code] || code; }).join(', ')
            : 'не выбраны';
        var noteText = details.note.concat([
            '',
            'Это демонстрационная сделка отраслевого решения Clever.',
            'Выбраны подключения: ' + integrations + '.'
        ]).join('\n');

        if (!first) {
            return Promise.reject(new Error('Не найден первый этап воронки «' + definition.name + '»'));
        }

        return request('POST', '/api/v4/leads/complex', [{
            name: details.name,
            price: details.price,
            pipeline_id: pipeline.id,
            status_id: first.id,
            custom_fields_values: sampleCustomFields(fields, details, index + 1),
            tags_to_add: [{ name: 'Демо отраслевого решения' }],
            _embedded: {
                contacts: [{
                    first_name: 'Анна',
                    last_name: 'Пример',
                    custom_fields_values: [{
                        field_code: 'PHONE',
                        values: [{ value: '+79990000000', enum_code: 'WORK' }]
                    }]
                }],
                companies: [{ name: 'Демонстрационная организация' }]
            }
        }]).then(function (response) {
            var lead = Array.isArray(response) ? response[0] : embedded(response, 'leads')[0];

            if (!lead || !lead.id) {
                throw new Error('amoCRM не вернула ID демонстрационной сделки');
            }

            return request('POST', '/api/v4/leads/' + lead.id + '/notes', [{
                note_type: 'common',
                params: { text: noteText }
            }]);
        });
    }

    function createSamples(pipelines, fields, options) {
        return sequence(PIPELINES, function (definition, index) {
            return createSample(
                definition,
                findPipeline(pipelines, definition.name),
                fields,
                options,
                index
            );
        });
    }

    function run(allStepsData, config) {
        var options = normalizeWizardData(allStepsData);
        var state = {};

        return ensurePipelines().then(function (pipelines) {
            state.pipelines = pipelines;
            return ensureIntegrationGroup();
        }).then(function (group) {
            return ensureFields(state.pipelines, group, options);
        }).then(function (fields) {
            state.fields = fields;
            return installSelectedWidgets(options.integrations, config);
        }).then(function (widgetResult) {
            state.widgetResult = widgetResult;
            return createSamples(state.pipelines, state.fields, options);
        }).then(function () {
            return {
                options: options,
                pipelines: state.pipelines,
                widgets: state.widgetResult
            };
        });
    }

    return {
        run: run,
        normalizeWizardData: normalizeWizardData
    };
});
