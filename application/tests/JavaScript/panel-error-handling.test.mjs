import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import test from 'node:test';

const filamentErrors = readFileSync(new URL('../../vendor/filament/filament/resources/js/error-notifications.js', import.meta.url), 'utf8');
const livewire = readFileSync(new URL('../../vendor/livewire/livewire/dist/livewire.js', import.meta.url), 'utf8');

// Exercise the installed framework handlers, including Livewire 4 object payloads.
function requestsAfterFailure({ notificationsEnabled, status = 419, offline = false }) {
    let interceptor;
    const pending = [{ components: [{ snapshot: JSON.stringify({ data: {} }) }] }];
    const notificationPayload = { components: [{ snapshot: JSON.stringify({ data: { isFilamentNotificationsComponent: true } }) }] };
    let notifications = 0;
    let requests = 0;
    let prevented = 0;
    const window = {
        filamentErrorNotifications: notificationsEnabled
            ? { '': { title: 'Error', body: 'Request failed', isDisabled: false, isHidden: false } }
            : null,
    };

    class FilamentNotification {
        title() { return this; }
        body() { return this; }
        danger() { return this; }
        send() { notifications++; pending.push(notificationPayload); }
    }

    runInNewContext(filamentErrors, {
        window,
        FilamentNotification,
        Livewire: { interceptRequest(callback) { interceptor = callback; } },
        document: { addEventListener(event, callback) { assert.equal(event, 'livewire:init'); callback(); } },
    });

    while (pending.length && requests < 100) {
        const payload = pending.shift();
        let onError;
        let onFailure;
        interceptor({
            request: { payload },
            onError(callback) { onError = callback; },
            onFailure(callback) { onFailure = callback; },
        });
        requests++;
        if (offline) onFailure();
        else onError({ response: { status }, preventDefault() { prevented++; } });
    }

    return { requests, notifications, prevented, pending: pending.length };
}

test('reproduces recursive error notifications with the installed Filament and Livewire 4 payload', () => {
    const result = requestsAfterFailure({ notificationsEnabled: true });
    assert.equal(result.requests, 100);
    assert.equal(result.notifications, 100);
    assert.equal(result.pending, 1);
});

for (const status of [401, 403, 419, 429, 500, 502, 503]) {
    test(`HTTP ${status} does not generate follow-up notification requests or suppress native handling`, () => {
        assert.deepEqual(requestsAfterFailure({ notificationsEnabled: false, status }), {
            requests: 1, notifications: 0, prevented: 0, pending: 0,
        });
    });
}

test('offline failure does not start a notification request loop', () => {
    assert.deepEqual(requestsAfterFailure({ notificationsEnabled: false, offline: true }), {
        requests: 1, notifications: 0, prevented: 0, pending: 0,
    });
});

test('native 419 handler expires the session once without forcing a reload', () => {
    const start = livewire.indexOf('          if (response.status === 419) {');
    const end = livewire.indexOf('          if (response.aborted)', start);
    assert.ok(start >= 0 && end > start);
    let prompts = 0;
    let reloads = 0;
    const context = {
        response: { status: 419 }, sessionExpired: false,
        confirm() { prompts++; return false; },
        window: { location: { reload() { reloads++; } } },
    };
    const handler = `(function () { ${livewire.slice(start, end)} })()`;
    runInNewContext(handler, context);
    runInNewContext(handler, context);
    assert.equal(context.sessionExpired, true);
    assert.equal(prompts, 1);
    assert.equal(reloads, 0);
    assert.match(livewire, /pauseWhile\(\(\) => sessionIsExpired\(\)\)/);
});
