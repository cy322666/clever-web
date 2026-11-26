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
