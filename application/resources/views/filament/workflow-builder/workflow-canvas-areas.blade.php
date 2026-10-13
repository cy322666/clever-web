<div class="workflow-canvas-areas" wire:ignore>
    <template x-for="area in canvasAreas()" :key="area.id">
        <section class="workflow-canvas-area" :data-workflow-area-id="area.id" :style="areaStyle(area)" :aria-label="'Этап: ' + area.title">
            <header class="workflow-canvas-area__header" x-on:pointerdown.stop="startAreaDrag(area.id, $event)" x-on:dblclick.stop x-on:contextmenu.stop>
                @unless($readOnlyAreas ?? false)
                    <input type="text" :value="area.title" maxlength="120" aria-label="Название этапа" x-on:pointerdown.stop x-on:input="renameCanvasArea(area.id, $el.value, false)" x-on:blur="renameCanvasArea(area.id, $el.value)" x-on:keydown.enter.prevent.stop="$el.blur()" x-on:keydown.escape.prevent.stop="$el.blur()" />
                    <button type="button" class="workflow-canvas-area__move" title="Переместить область. Стрелки на клавиатуре тоже работают" aria-label="Переместить область" x-on:keydown="areaKeyboard(area.id, $event)"><x-filament::icon icon="heroicon-o-arrows-pointing-out" class="h-4 w-4"/></button>
                    <button type="button" class="workflow-canvas-area__delete" x-on:pointerdown.stop x-on:click.stop="removeCanvasArea(area.id)" title="Удалить только область, ноды останутся" aria-label="Удалить область"><x-filament::icon icon="heroicon-o-trash" class="h-4 w-4"/></button>
                @else
                    <span x-text="area.title"></span>
                @endunless
            </header>
            @unless($readOnlyAreas ?? false)
                <button type="button" class="workflow-canvas-area__resize" aria-label="Изменить размер области" title="Изменить размер области" x-on:pointerdown.stop="startAreaDrag(area.id, $event, true)" x-on:keydown="areaKeyboard(area.id, $event, true)"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M5 13 13 5M10 13l3-3"/></svg></button>
            @endunless
        </section>
    </template>
</div>
