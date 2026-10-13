<template x-teleport="body">
    <span class="workflow-copy-feedback" role="status" aria-live="polite" x-cloak
        x-show="copiedRow !== null && copyPosition !== null"
        :style="{ left: (copyPosition?.left ?? 0) + 'px', top: (copyPosition?.top ?? 0) + 'px' }"
        x-on:pointerdown.window="clearCopyFeedback()"
        x-on:keydown.window="clearCopyFeedback()"
        x-on:scroll.window.capture="clearCopyFeedback()"
        x-on:resize.window="clearCopyFeedback()"
    ><span aria-hidden="true">✓</span> Скопировано</span>
</template>
