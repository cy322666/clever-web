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

window.workflowWorkbench = () => ({
    masksOpen: false,
    maskDock: {
        x: 16,
        y: 16,
        dragging: false,
        offsetX: 0,
        offsetY: 0,
        pointerId: null,
    },
    maskDockStorageKey: 'clever.workflow.maskDockPosition',

    initMaskDock() {
        this.restoreMaskDockPosition();
        this.$nextTick(() => this.keepMasksDockInViewport());
    },

    openMasksDock() {
        this.masksOpen = true;
        this.$nextTick(() => this.keepMasksDockInViewport());
    },

    maskDockStyle() {
        return `left: ${this.maskDock.x}px; top: ${this.maskDock.y}px;`;
    },

    restoreMaskDockPosition() {
        try {
            const stored = JSON.parse(window.localStorage.getItem(this.maskDockStorageKey) || '{}');

            if (Number.isFinite(stored.x) && Number.isFinite(stored.y)) {
                this.maskDock.x = stored.x;
                this.maskDock.y = stored.y;
            }
        } catch {
            this.maskDock.x = 16;
            this.maskDock.y = 16;
        }
    },

    saveMaskDockPosition() {
        window.localStorage.setItem(this.maskDockStorageKey, JSON.stringify({
            x: this.maskDock.x,
            y: this.maskDock.y,
        }));
    },

    startMaskDockDrag(event) {
        if (event.button !== undefined && event.button !== 0) {
            return;
        }

        if (event.target.closest('button, a, input, textarea, select, [role="button"]')) {
            return;
        }

        const rect = this.$refs.maskDock?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        this.maskDock.dragging = true;
        this.maskDock.pointerId = event.pointerId;
        this.maskDock.offsetX = event.clientX - rect.left;
        this.maskDock.offsetY = event.clientY - rect.top;
        this.$refs.maskDock?.setPointerCapture?.(event.pointerId);
        event.preventDefault();
    },

    dragMaskDock(event) {
        if (!this.maskDock.dragging || event.pointerId !== this.maskDock.pointerId) {
            return;
        }

        this.setMaskDockPosition(event.clientX - this.maskDock.offsetX, event.clientY - this.maskDock.offsetY);
    },

    stopMaskDockDrag(event) {
        if (!this.maskDock.dragging || event.pointerId !== this.maskDock.pointerId) {
            return;
        }

        this.maskDock.dragging = false;
        this.maskDock.pointerId = null;
        this.$refs.maskDock?.releasePointerCapture?.(event.pointerId);
        this.keepMasksDockInViewport();
        this.saveMaskDockPosition();
    },

    setMaskDockPosition(x, y) {
        const rect = this.$refs.maskDock?.getBoundingClientRect();
        const width = rect?.width || 448;
        const height = rect?.height || Math.min(window.innerHeight - 32, 720);
        const gap = 8;

        this.maskDock.x = Math.min(Math.max(gap, x), Math.max(gap, window.innerWidth - width - gap));
        this.maskDock.y = Math.min(Math.max(gap, y), Math.max(gap, window.innerHeight - height - gap));
    },

    keepMasksDockInViewport() {
        this.setMaskDockPosition(this.maskDock.x, this.maskDock.y);
    },
});

window.lockCleverSidebarCollapsed = () => {
    const sidebar = window.Alpine?.store?.('sidebar');

    if (!sidebar) {
        return false;
    }

    const forceClosed = () => {
        sidebar.isOpen = false;
        sidebar.isOpenDesktop = false;
    };

    forceClosed();
    sidebar.open = forceClosed;

    return true;
};

window.scheduleCleverSidebarLock = (attempt = 0) => {
    window.setTimeout(() => {
        if (window.lockCleverSidebarCollapsed()) {
            return;
        }

        if (attempt < 20) {
            window.scheduleCleverSidebarLock(attempt + 1);
        }
    }, attempt === 0 ? 0 : 50);
};

document.addEventListener('alpine:init', () => {
    window.scheduleCleverSidebarLock();
});

document.addEventListener('DOMContentLoaded', () => {
    window.scheduleCleverSidebarLock();
});

document.addEventListener('livewire:navigated', () => {
    window.scheduleCleverSidebarLock();
});

window.addEventListener('resize', () => {
    window.lockCleverSidebarCollapsed?.();
});
