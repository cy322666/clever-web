@php
    $testUrl = $url ? $url.'?_workflow_test=1' : null;
@endphp
<div class="workflow-webhook-settings"
    x-data="{ latest: @js($preview), displayed: @js($preview), listening: false, baseline: null, test: true, copied: false,
        receive(preview) {
            this.latest = preview;
            if (this.listening && preview && preview.id !== this.baseline) { this.displayed = preview; this.listening = false; }
        }
    }"
    x-on:workflow-webhook-received="receive($event.detail.preview)">
    <span hidden wire:key="webhook-preview-{{ $preview['id'] ?? 'empty' }}" x-init="$dispatch('workflow-webhook-received', {preview: @js($preview)})"></span>
    <section>
        @if($url)
            <div class="workflow-value-field__modes" style="justify-content:flex-start;margin-bottom:16px">
                <button type="button" :aria-pressed="test" x-on:click="test = true">Тестовый URL</button>
                <button type="button" :aria-pressed="!test" x-on:click="test = false">Рабочий URL</button>
            </div>
            <label for="workflow-webhook-url">URL вебхука</label>
            <input id="workflow-webhook-url" readonly :value="test ? @js($testUrl) : @js($url)" x-on:click="$el.select()" />
            <p x-text="test ? 'Тестовый запрос только принимает данные — действия не запускаются.' : 'Рабочий URL запускает активный сценарий.'"></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="workflow-node-run__button" x-on:click="if (listening) { listening = false; } else { test = true; baseline = latest?.id; displayed = null; listening = true; }" x-text="listening ? 'Остановить ожидание' : 'Слушать тестовый запрос'"></button>
                <button type="button" class="workflow-workbench__quick-action" aria-label="Скопировать URL" title="Скопировать URL"
                    x-on:click="navigator.clipboard.writeText(test ? @js($testUrl) : @js($url)).then(() => copied = true)">
                    <x-filament::icon icon="heroicon-o-clipboard-document" class="h-4 w-4"/>
                </button>
            </div>
            <p role="status" x-show="listening">Ожидание запроса…</p>
            <p role="status" x-show="copied" x-cloak>URL скопирован</p>
        @else
            <p>Сохраните сценарий с этой нодой — появится URL вебхука.</p>
        @endif
    </section>
    <section aria-label="Полученные данные вебхука">
        <label>Полученные данные</label>
        <p x-show="!displayed" x-text="listening ? 'Отправьте запрос на тестовый URL.' : 'Запросов пока нет.'"></p>
        <template x-if="displayed">
            <div>
                <p x-text="displayed.method + ' · ' + displayed.received_at"></p>
                <pre x-text="JSON.stringify({body: displayed.payload, query: displayed.query, headers: displayed.headers}, null, 2)"></pre>
            </div>
        </template>
    </section>
</div>
