import test from 'node:test';
import assert from 'node:assert/strict';
import { formatSelectedRows, serviceRequestCopy } from '../../resources/js/service-request-copy.js';

const requests = [
    { id: '1', reference: 'FIRST', inputs: [
        { label: 'NIN number', value: '01234567890' },
        { label: 'First name', value: 'Taylor' },
        { label: 'Phone number', value: '08165072514' },
        { label: 'Middle name', value: '' },
    ] },
    { id: '2', reference: 'SECOND', inputs: [
        { label: 'Tracking ID', value: 'ABC123DEF456GH7' },
        { label: 'Complain', value: 'Please check\n<script>example()</script>' },
    ] },
];

const firstText = 'NIN NUMBER: 01234567890\nFIRST NAME: TAYLOR\nPHONE NUMBER: 08165072514';
const bothText = firstText + '\n\nTRACKING ID: ABC123DEF456GH7\nCOMPLAIN: PLEASE CHECK\n<SCRIPT>EXAMPLE()</SCRIPT>';

function container() {
    return { querySelectorAll(selector) {
        assert.equal(selector, '[data-copy-row]');
        return requests.map((row) => ({
            dataset: { copyRow: row.id },
            querySelectorAll(selector) {
                assert.equal(selector, '[data-copy-field]');
                return row.inputs.map(({ label, value }) => ({ dataset: { copyLabel: label, copyValue: value } }));
            },
        }));
    } };
}

function clipboard(context, writeText) {
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { writeText } } });
    context.after(() => {
        if (previous) { Object.defineProperty(globalThis, 'navigator', previous); } else { delete globalThis.navigator; }
    });
}

test('selecting one row includes all submitted fields and preserves leading zeroes', () => {
    assert.equal(formatSelectedRows(requests, ['1']), firstText);
    assert.equal(formatSelectedRows(requests, [1]), firstText);
});

test('multiple selected rows copy all inputs in table order grouped by request', () => {
    assert.equal(formatSelectedRows(requests, ['2', '1']), bothText);
    assert.equal(formatSelectedRows(requests, ['1', '1', '2']), bothText);
});

test('copied attachment links retain their working URL while labels are uppercase', () => {
    assert.equal(formatSelectedRows([{
        id: '3', reference: 'NOT-COPIED',
        inputs: [
            { label: 'First name', value: 'Taylor' },
            { label: 'Clear photo', value: 'https://Example.test/service-requests/AbC/proof', preserveCase: true },
        ],
    }], ['3']), 'FIRST NAME: TAYLOR\nCLEAR PHOTO: https://Example.test/service-requests/AbC/proof');
});

test('unselected rows and empty selections are omitted', () => {
    assert.equal(formatSelectedRows(requests, ['2']), 'TRACKING ID: ABC123DEF456GH7\nCOMPLAIN: PLEASE CHECK\n<SCRIPT>EXAMPLE()</SCRIPT>');
    assert.equal(formatSelectedRows(requests, []), '');
    assert.equal(formatSelectedRows(requests, ['missing']), '');
    assert.equal(formatSelectedRows([{ id: '3', reference: 'EMPTY', inputs: [] }], ['3']), '');
});

test('row selection updates copy all current selected requests', async (context) => {
    const copied = [];
    clipboard(context, async (text) => { copied.push(text); });
    const state = serviceRequestCopy();
    state.$root = container();
    state.$el = { querySelectorAll() { return []; } };
    state.selectedRows = ['1'];
    await state.copySelected();
    state.selectedRows = ['1', '2'];
    await state.copySelected();
    state.selectedRows = ['2'];
    await state.copySelected();
    assert.deepEqual(copied, [firstText, bothText, 'TRACKING ID: ABC123DEF456GH7\nCOMPLAIN: PLEASE CHECK\n<SCRIPT>EXAMPLE()</SCRIPT>']);
    assert.equal(state.copyStatus, 'Selected request inputs copied.');
});

test('select all copies every visible row and clear removes the selection', async (context) => {
    const copied = [];
    clipboard(context, async (text) => { copied.push(text); });
    const state = serviceRequestCopy();
    state.$root = container();
    state.$el = { querySelectorAll() { return []; } };
    await state.selectAll();
    assert.deepEqual(state.selectedRows, ['1', '2']);
    assert.deepEqual(copied, [bothText]);
    state.clearSelection();
    await state.copySelected();
    assert.deepEqual(state.selectedRows, []);
    assert.equal(state.copyStatus, '');
    assert.deepEqual(copied, [bothText]);
});

test('rapid row changes write the latest combined selection last', async (context) => {
    const copied = [];
    let finishFirst;
    clipboard(context, async (text) => {
        if (copied.length === 0) {
            copied.push(text);
            await new Promise((resolve) => { finishFirst = resolve; });
        } else {
            copied.push(text);
        }
    });
    const state = serviceRequestCopy();
    state.$root = container();
    state.$el = { querySelectorAll() { return []; } };
    state.selectedRows = ['1'];
    const first = state.copySelected();
    state.selectedRows = ['1', '2'];
    const second = state.copySelected();
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(copied, [firstText]);
    finishFirst();
    await Promise.all([first, second]);
    assert.deepEqual(copied, [firstText, bothText]);
});

test('failed clipboard and fallback copying show a retry message', async (context) => {
    clipboard(context, async () => { throw new Error('Clipboard denied'); });
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'document');
    Object.defineProperty(globalThis, 'document', { configurable: true, value: {
        createElement() { return { style: {}, setAttribute() {}, focus() {}, select() {}, remove() {} }; },
        execCommand() { return false; },
    } });
    context.after(() => {
        if (previous) { Object.defineProperty(globalThis, 'document', previous); } else { delete globalThis.document; }
    });
    const state = serviceRequestCopy();
    state.$root = { ...container(), appendChild() {} };
    state.$el = { querySelectorAll() { return []; } };
    state.selectedRows = ['1'];
    await state.copySelected();
    assert.equal(state.copyStatus, 'Copy failed. Use Copy selected rows to try again.');
});
