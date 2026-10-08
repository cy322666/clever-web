<script>
    (() => {
        const exact = new Map(Object.entries({
            'Dashboard': 'Панель',
            'Horizon - Dashboard': 'Очереди - Панель',
            'Horizon - Monitoring': 'Очереди - Мониторинг',
            'Horizon - Metrics': 'Очереди - Метрики',
            'Horizon - Batches': 'Очереди - Пакеты',
            'Horizon - Pending Jobs': 'Очереди - Ожидающие задачи',
            'Horizon - Completed Jobs': 'Очереди - Завершенные задачи',
            'Horizon - Silenced Jobs': 'Очереди - Скрытые задачи',
            'Horizon - Failed Jobs': 'Очереди - Ошибки задач',
            'Monitoring': 'Мониторинг',
            'Metrics': 'Метрики',
            'Batches': 'Пакеты',
            'Pending Jobs': 'Ожидающие задачи',
            'Completed Jobs': 'Завершенные задачи',
            'Silenced Jobs': 'Скрытые задачи',
            'Failed Jobs': 'Ошибки задач',
            'Overview': 'Обзор',
            'Jobs Per Minute': 'Задач в минуту',
            'Jobs Past Hour': 'Задач за последний час',
            'Failed Jobs Past 7 Days': 'Ошибок за 7 дней',
            'Status': 'Статус',
            'Active': 'Активен',
            'Paused': 'На паузе',
            'Inactive': 'Неактивен',
            'Total Processes': 'Всего процессов',
            'Max Wait Time': 'Макс. ожидание',
            'Max Runtime': 'Макс. время выполнения',
            'Max Throughput': 'Макс. пропускная способность',
            'Current Workload': 'Текущая нагрузка',
            'Queue': 'Очередь',
            'Queues': 'Очереди',
            'Jobs': 'Задачи',
            'Processes': 'Процессы',
            'Wait': 'Ожидание',
            'Supervisor': 'Супервизор',
            'Connection': 'Подключение',
            'Balancing': 'Балансировка',
            'Batch': 'Пакет',
            'Batch Preview': 'Просмотр пакета',
            'Search Batches': 'Поиск пакетов',
            "There aren't any batches.": 'Пакетов нет.',
            'Size': 'Размер',
            'Completion': 'Завершение',
            'Created': 'Создано',
            'Finished': 'Завершено',
            'Cancelled': 'Отменено',
            'Total Jobs': 'Всего задач',
            'Processed Jobs': 'Обработано задач',
            '(Including Failed)': '(включая ошибки)',
            'Retry Failed Jobs': 'Перезапустить задачи с ошибкой',
            'Failed': 'Ошибка',
            'Retry': 'Повторить',
            'Retry Job': 'Перезапустить задачу',
            'Job': 'Задача',
            'Job Preview': 'Просмотр задачи',
            'Runtime': 'Время выполнения',
            'Failed Jobs': 'Ошибки задач',
            'Search Tags': 'Поиск тегов',
            "There aren't any failed jobs.": 'Ошибок задач нет.',
            'Attempts': 'Попытки',
            'Retries': 'Повторы',
            'Retry of ID': 'Повтор ID',
            'Retry Time': 'Время повтора',
            'Tags': 'Теги',
            'Exception': 'Исключение',
            'Exception Context': 'Контекст исключения',
            'Data': 'Данные',
            'Recent Retries': 'Последние повторы',
            'Monitor Tag': 'Отслеживать тег',
            'Monitor New Tag': 'Отслеживать новый тег',
            'Stop Monitoring': 'Остановить мониторинг',
            "You're not monitoring any tags.": 'Теги не отслеживаются.',
            'Tag': 'Тег',
            'Recent Jobs': 'Последние задачи',
            'Recent Jobs for': 'Последние задачи для',
            "There aren't any jobs for this tag.": 'Для этого тега задач нет.',
            'Queued': 'Поставлено в очередь',
            'Pushed': 'Добавлено',
            'Delayed Until': 'Отложено до',
            "There aren't any pending jobs.": 'Ожидающих задач нет.',
            "There aren't any completed jobs.": 'Завершенных задач нет.',
            "There aren't any silenced jobs.": 'Скрытых задач нет.',
            "There aren't any jobs.": 'Задач нет.',
            "There aren't any queues.": 'Очередей нет.',
            'Loading...': 'Загрузка...',
            'Load New Entries': 'Загрузить новые записи',
            'Previous': 'Назад',
            'Next': 'Вперед',
            'Name': 'Название',
            'ID': 'ID',
            'Throughput': 'Пропускная способность',
            'Switch Theme': 'Переключить тему',
            'Auto Load New Entries': 'Автозагрузка новых записей',
            'Show All': 'Показать все',
            'OK': 'ОК',
            'Cancel': 'Отмена',
            'Pending': 'Ожидает',
        }));

        const patterns = [
            [/^Jobs Past (.+)$/u, 'Задач за $1'],
            [/^Failed Jobs Past (.+)$/u, 'Ошибок за $1'],
            [/^Throughput - (.+)$/u, 'Пропускная способность - $1'],
            [/^Runtime - (.+)$/u, 'Время выполнения - $1'],
            [/^Delayed for (.+)$/u, 'Задержка: $1'],
            [/^Queue: /u, 'Очередь: '],
            [/\| Attempts: /gu, '| Попытки: '],
            [/\| Retry of/gu, '| Повтор задачи'],
            [/\| Tags: /gu, '| Теги: '],
            [/(\d+) more$/u, '$1 еще'],
            [/^Total retries: /u, 'Всего повторов: '],
            [/, Last retry status: /u, ', последний статус: '],
        ];

        const ignoredTags = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'CODE', 'PRE']);

        const translateValue = (value) => {
            if (!value) {
                return value;
            }

            const trimmed = value.trim();
            if (exact.has(trimmed)) {
                return value.replace(trimmed, exact.get(trimmed));
            }

            return patterns.reduce((text, [from, to]) => text.replace(from, to), value);
        };

        const translateElement = (element) => {
            if (!element || ignoredTags.has(element.tagName)) {
                return;
            }

            ['title', 'placeholder', 'aria-label'].forEach((attribute) => {
                const value = element.getAttribute(attribute);
                const translated = translateValue(value);

                if (translated && translated !== value) {
                    element.setAttribute(attribute, translated);
                }
            });
        };

        const translateTextNode = (node) => {
            if (!node.parentElement || ignoredTags.has(node.parentElement.tagName)) {
                return;
            }

            const translated = translateValue(node.nodeValue);
            if (translated !== node.nodeValue) {
                node.nodeValue = translated;
            }
        };

        const translateTree = (root = document.body) => {
            if (!root) {
                return;
            }

            if (root.nodeType === Node.ELEMENT_NODE) {
                translateElement(root);
            }

            const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
            let node;

            while ((node = walker.nextNode())) {
                if (node.nodeType === Node.TEXT_NODE) {
                    translateTextNode(node);
                } else if (node.nodeType === Node.ELEMENT_NODE) {
                    translateElement(node);
                }
            }
        };

        const translateDocumentTitle = () => {
            const translated = translateValue(document.title);

            if (translated !== document.title) {
                document.title = translated;
            }
        };

        const boot = () => {
            translateDocumentTitle();
            translateTree();

            new MutationObserver((mutations) => {
                translateDocumentTitle();

                for (const mutation of mutations) {
                    if (mutation.type === 'characterData') {
                        translateTextNode(mutation.target);
                    }

                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === Node.TEXT_NODE) {
                            translateTextNode(node);
                        } else if (node.nodeType === Node.ELEMENT_NODE) {
                            translateTree(node);
                        }
                    });
                }
            }).observe(document.body, {
                childList: true,
                subtree: true,
                characterData: true,
            });

            setInterval(translateDocumentTitle, 500);
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot, { once: true });
        } else {
            boot();
        }
    })();
</script>
