let mermaidRenderCounter = 0;
let mermaidRenderScheduled = false;
let mermaidModulePromise = null;

const currentMermaidTheme = () => (
    document.documentElement.classList.contains('dark') ? 'dark' : 'base'
);

const getMermaid = async () => {
    mermaidModulePromise ??= import('mermaid').then((module) => module.default);

    return mermaidModulePromise;
};

const configureMermaid = (mermaid) => {
    mermaid.initialize({
        startOnLoad: false,
        securityLevel: 'loose',
        theme: currentMermaidTheme(),
        flowchart: {
            curve: 'linear',
            htmlLabels: true,
            useMaxWidth: false,
            diagramPadding: 36,
            nodeSpacing: 64,
            rankSpacing: 96,
        },
        themeVariables: {
            fontFamily: 'inherit',
            primaryColor: '#eff6ff',
            primaryBorderColor: '#93c5fd',
            primaryTextColor: '#0f172a',
            lineColor: '#94a3b8',
            secondaryColor: '#f8fafc',
            tertiaryColor: '#ecfdf5',
        },
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

document.addEventListener('DOMContentLoaded', scheduleWorkflowMermaidRender);
document.addEventListener('livewire:navigated', scheduleWorkflowMermaidRender);
window.addEventListener('workflow-mermaid-render', scheduleWorkflowMermaidRender);

new MutationObserver(scheduleWorkflowMermaidRender).observe(document.documentElement, {
    childList: true,
    subtree: true,
});
