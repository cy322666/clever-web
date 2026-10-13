<svg class="workflow-canvas-grid" aria-hidden="true" focusable="false" x-id="['workflow-grid']" wire:ignore>
    <defs>
        <pattern x-bind:id="$id('workflow-grid')" patternUnits="userSpaceOnUse"
            x-bind:width="gridSpacing" x-bind:height="gridSpacing"
            x-bind:pattern-transform.camel="gridTransform()">
            <circle x-bind:cx="gridSpacing / 2" x-bind:cy="gridSpacing / 2" r="0.9"/>
        </pattern>
    </defs>
    <rect width="100%" height="100%" x-bind:fill="`url(#${$id('workflow-grid')})`" x-bind:opacity="gridOpacity()"/>
</svg>
