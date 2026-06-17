let mermaidRenderCounter = 0;
let mermaidRenderScheduled = false;
let mermaidModulePromise = null;

const currentMermaidTheme = () => (
    document.documentElement.classList.contains('dark') ? 'dark' : 'light'
);

const mermaidThemeVariables = () => {
    if (currentMermaidTheme() === 'dark') {
        return {
            fontFamily: 'inherit',
            primaryColor: '#111827',
            primaryBorderColor: '#475569',
            primaryTextColor: '#f8fafc',
            lineColor: '#64748b',
            secondaryColor: '#0f172a',
            tertiaryColor: '#111827',
            mainBkg: '#111827',
            nodeBorder: '#475569',
            clusterBkg: '#0f172a',
            clusterBorder: '#334155',
            edgeLabelBackground: '#111827',
        };
    }

    return {
        fontFamily: 'inherit',
        primaryColor: '#ffffff',
        primaryBorderColor: '#cbd5e1',
        primaryTextColor: '#111827',
        lineColor: '#64748b',
        secondaryColor: '#f8fafc',
        tertiaryColor: '#ffffff',
        mainBkg: '#ffffff',
        nodeBorder: '#cbd5e1',
        clusterBkg: '#f8fafc',
        clusterBorder: '#e2e8f0',
        edgeLabelBackground: '#ffffff',
    };
};

const getMermaid = async () => {
    mermaidModulePromise ??= import('mermaid').then((module) => module.default);

    return mermaidModulePromise;
};

const configureMermaid = (mermaid) => {
    mermaid.initialize({
        startOnLoad: false,
        securityLevel: 'loose',
        theme: 'base',
        flowchart: {
            curve: 'linear',
            htmlLabels: true,
            useMaxWidth: false,
            diagramPadding: 36,
            nodeSpacing: 64,
            rankSpacing: 96,
        },
        themeVariables: mermaidThemeVariables(),
    });
};

const renderWorkflowMermaidMap = async (map) => {
    const source = map.querySelector('[data-workflow-mermaid-source]')?.value?.trim() ?? '';
    const target = map.querySelector('[data-workflow-mermaid-target]');
    const theme = currentMermaidTheme();
    const renderKey = `${theme}:${source}`;

    if (!source || !target || map.dataset.renderKey === renderKey) {
        return;
    }

    map.dataset.renderKey = renderKey;
    target.innerHTML = '<div class="workflow-mermaid-map__loading">Строим карту...</div>';

    try {
        const mermaid = await getMermaid();

        configureMermaid(mermaid);

        const renderId = `workflow-mermaid-map-${++mermaidRenderCounter}`;
        const {svg, bindFunctions} = await mermaid.render(renderId, source);

        target.innerHTML = svg;
        bindFunctions?.(target);

        window.requestAnimationFrame(() => {
            target.scrollLeft = Math.max(0, (target.scrollWidth - target.clientWidth) / 2);
        });
    } catch (error) {
        map.dataset.renderKey = '';
        target.innerHTML = '<div class="workflow-mermaid-map__error">Не удалось построить карту связей</div>';

        console.error('Workflow Mermaid render failed', error);
    }
};

const renderWorkflowMermaidMaps = () => {
    document
        .querySelectorAll('[data-workflow-mermaid-map]')
        .forEach((map) => renderWorkflowMermaidMap(map));
};

const scheduleWorkflowMermaidRender = () => {
    if (mermaidRenderScheduled) {
        return;
    }

    mermaidRenderScheduled = true;

    window.requestAnimationFrame(() => {
        mermaidRenderScheduled = false;
        renderWorkflowMermaidMaps();
    });
};

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

document.addEventListener('DOMContentLoaded', scheduleWorkflowMermaidRender);
document.addEventListener('livewire:navigated', scheduleWorkflowMermaidRender);
window.addEventListener('workflow-mermaid-render', scheduleWorkflowMermaidRender);

new MutationObserver(scheduleWorkflowMermaidRender).observe(document.documentElement, {
    childList: true,
    subtree: true,
});
