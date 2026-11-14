import assert from 'node:assert/strict';
import test from 'node:test';
import { notificationBatches } from '../../resources/js/notification-batches.js';

test('batches wait for confirmation and run one request at a time', async () => {
    const calls = [];
    let inFlight = false;
    let batch = 0;
    const state = notificationBatches({
        async confirmSending() {
            calls.push('confirmed');
            return { status: 'ready' };
        },
        async sendNextBatch() {
            assert.equal(inFlight, false);
            inFlight = true;
            await Promise.resolve();
            calls.push(++batch);
            inFlight = false;
            return { status: batch === 3 ? 'completed' : 'ready' };
        },
    });
    const sending = state.start();
    assert.equal(state.busy, true);
    await state.start();
    await sending;
    assert.deepEqual(calls, ['confirmed', 1, 2, 3]);
    assert.equal(state.busy, false);
});

test('an uncertain result stops all later batches', async () => {
    let batches = 0;
    const state = notificationBatches({
        async confirmSending() { return { status: 'ready' }; },
        async sendNextBatch() {
            batches++;
            return { status: 'stopped', totals: { uncertain: 500 } };
        },
    });
    await state.start();
    assert.equal(batches, 1);
    assert.equal(state.busy, false);
});

test('WhatsApp pauses five seconds between recipients while controls remain busy', async () => {
    const calls = [];
    let releasePause;
    let batch = 0;
    const state = notificationBatches({
        async confirmSending() { return { status: 'ready' }; },
        async sendNextBatch() {
            calls.push(++batch);
            return { status: batch === 2 ? 'completed' : 'ready' };
        },
    }, 5000, (milliseconds) => {
        calls.push(['pause', milliseconds]);
        return new Promise((resolve) => { releasePause = resolve; });
    });
    const sending = state.start();
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(calls, [1, ['pause', 5000]]);
    assert.equal(state.busy, true);
    await state.start();
    assert.equal(batch, 1);
    releasePause();
    await sending;
    assert.deepEqual(calls, [1, ['pause', 5000], 2]);
    assert.equal(state.busy, false);
});

test('an interrupted request reconciles once and never retries the send', async () => {
    let sends = 0;
    let reconciliations = 0;
    const state = notificationBatches({
        async confirmSending() { return { status: 'ready' }; },
        async sendNextBatch() {
            sends++;
            throw new Error('Network interrupted');
        },
        async stopInterrupted() {
            reconciliations++;
            throw new Error('Still offline');
        },
    });
    await state.start();
    assert.equal(sends, 1);
    assert.equal(reconciliations, 1);
    assert.equal(state.interrupted, true);
    assert.equal(state.busy, false);
});
