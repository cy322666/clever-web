<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            Как собрать процесс
        </x-slot>

        <div class="fi-ta-text grid gap-4 text-sm text-gray-700 dark:text-gray-200">
            <p>
                Процесс состоит из одного триггера и набора действий. Триггер отвечает за запуск, действия выполняются
                по порядку.
            </p>

            <ul class="grid gap-2">
                <li><strong>1.</strong> Выберите событие amoCRM, ручной запуск, дату или расписание.</li>
                <li><strong>2.</strong> Добавьте действие: создать задачу, изменить поле, сменить статус, теги и другие
                    операции.
                </li>
                <li><strong>3.</strong> Для ветвления используйте действие <strong>Условие</strong>: ветка <strong>ЕСЛИ
                        ДА</strong> выполнится при совпадении, <strong>ЕСЛИ НЕТ</strong> — если условие не прошло.
                </li>
                <li><strong>4.</strong> В полях условия выбирайте переменные триггера: сделка, контакт, компания,
                    задача, примечание, статус или ответственный.
                </li>
                <li><strong>5.</strong> Перед включением процесса запустите тест и проверьте, что выбран нужный триггер
                    и действия стоят в правильном порядке.
                </li>
            </ul>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            Частые переменные
        </x-slot>

        <div class="fi-ta-text grid gap-3 text-sm text-gray-700 dark:text-gray-200">
            <p><code>{{ '{{trigger.lead.id}}' }}</code> — ID сделки</p>
            <p><code>{{ '{{trigger.lead.status_id}}' }}</code> — текущий статус сделки</p>
            <p><code>{{ '{{trigger.status.old_status_id}}' }}</code> — старый статус при смене статуса</p>
            <p><code>{{ '{{trigger.contact.name}}' }}</code> — имя контакта</p>
            <p><code>{{ '{{trigger.task.complete_till}}' }}</code> — срок задачи</p>
            <p><code>{{ '{{trigger.note.text}}' }}</code> — текст примечания</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
