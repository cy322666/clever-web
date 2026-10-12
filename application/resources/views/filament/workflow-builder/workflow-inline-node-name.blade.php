<h4 class="workflow-node-card__title" x-show="!renaming" x-bind:title="nodeName" x-text="nodeName" x-on:pointerdown.stop x-on:click.stop x-on:dblclick.stop="startRename()"></h4>
<div class="workflow-node-rename" x-show="renaming" x-cloak x-on:click.stop x-on:dblclick.stop x-on:pointerdown.stop>
    <input x-ref="nodeNameInput" x-model="nameDraft" maxlength="100" aria-label="Название ноды"
        x-bind:disabled="renamingBusy" x-bind:aria-invalid="Boolean(renameError)"
        x-on:keydown.enter.prevent.stop="commitRename()" x-on:keydown.escape.prevent.stop="renaming = false"
        x-on:blur="if (renaming && !renameError) commitRename()"/>
    <small role="alert" x-show="renameError" x-text="renameError"></small>
</div>
