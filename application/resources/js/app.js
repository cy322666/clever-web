window.workflowSortableList = (path) => ({
    path: path ?? '',
    draggingIndex: null,
    overIndex: null,
    clickSuppressed: false,

    dragPayload(event) {
        const raw = event.dataTransfer?.getData('application/x-workflow-action')
            || event.dataTransfer?.getData('text/plain');

        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch {
            return null;
        }
    },

    onDragStart(event, index) {
        this.draggingIndex = index;
        this.overIndex = index;
        this.clickSuppressed = false;

        const payload = JSON.stringify({path: this.path, index});

        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('application/x-workflow-action', payload);
        event.dataTransfer.setData('text/plain', payload);
    },

    onDragOver(event, index) {
        const payload = this.dragPayload(event);

        if (!payload || payload.path !== this.path) {
            return;
        }

        this.overIndex = index;
        event.dataTransfer.dropEffect = 'move';
    },

    onDrop(event, index) {
        const payload = this.dragPayload(event);

        if (!payload || payload.path !== this.path || Number(payload.index) === index) {
            this.resetDragState();

            return;
        }

        this.clickSuppressed = true;
        this.$wire.reorderWorkflowActions(this.path, Number(payload.index), index);
        this.resetDragState();

        window.setTimeout(() => {
            this.clickSuppressed = false;
        }, 120);
    },

    onDragEnd() {
        this.resetDragState();

        window.setTimeout(() => {
            this.clickSuppressed = false;
        }, 120);
    },

    suppressClickAfterDrag(event) {
        if (!this.clickSuppressed) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        event.stopPropagation();
        this.clickSuppressed = false;
    },

    resetDragState() {
        this.draggingIndex = null;
        this.overIndex = null;
    },
});
