import assert from 'node:assert/strict';
import test from 'node:test';
import { copyBatchReport, formatInstantIpeInput, formatInstantIpeField } from '../../resources/js/instant-ipe.js';

const first = 'ABC123DEF456GH7';
const second = 'XYZ987UVW654ST3';

test('single input is uppercase without truncating malformed IDs', () => {
    assert.equal(formatInstantIpeInput('000abc123def456', false), '000ABC123DEF456');
    assert.equal(formatInstantIpeInput('abc123def456gh78', false), 'ABC123DEF456GH78');
});

test('bulk input accepts pasted separators and puts every ID on its own line', () => {
    for (const separator of ['\n', '\r\n', ',', ';', ' ', '\t', '']) {
        assert.equal(formatInstantIpeInput(`abc123def456gh7${separator}xyz987uvw654st3`, true), `${first}\n${second}`);
    }
});

test('typing preserves an intentional trailing new line', () => {
    assert.equal(formatInstantIpeInput(`${first}\n`, true, true), `${first}\n`);
    assert.equal(formatInstantIpeInput(`${first},`, true, true), `${first}\n`);
    assert.equal(formatInstantIpeInput(`${first}\n`, true), first);
    assert.equal(formatInstantIpeInput(' , ; ', true, true), '');
});

test('bulk formatting retains incomplete and invalid IDs for validation', () => {
    assert.equal(formatInstantIpeInput(`${first}bad!`, true), `${first}BAD!`);
    assert.equal(formatInstantIpeInput(`${first}x`, true), `${first}\nX`);
    assert.equal(formatInstantIpeInput('bad-id', true), 'BAD-ID');
});

test('formatting updates the field and preserves the caret after automatic line breaks', () => {
    const field = {
        value: `${first.toLowerCase()}x`,
        selectionStart: 16,
        selectionEnd: 16,
        setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    };
    assert.equal(formatInstantIpeField(field, true), `${first}\nX`);
    assert.equal(field.value, `${first}\nX`);
    assert.equal(field.selectionStart, 17);
    assert.equal(field.selectionEnd, 17);
});

test('editing within an ID keeps the caret in place', () => {
    const field = {
        value: first.toLowerCase(), selectionStart: 4, selectionEnd: 4,
        setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
    };
    formatInstantIpeField(field, true);
    assert.equal(field.value, first);
    assert.equal(field.selectionStart, 4);
});

function reportDialog() {
    return {
        querySelector() { return { textContent: `${first}=CLEARED=01234567890\n${second}=Ready for Abis` }; },
        querySelectorAll() { return [['Total Records:', '2'], ['Total Successful:', '1'], ['Total Failed:', '1']]
            .map((values) => ({ children: values.map((textContent) => ({ textContent })) })); },
    };
}


test('copying a batch includes every report line and its summary as plain text', async (context) => {
    let copied;
    const previous = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { async writeText(text) { copied = text; } } } });
    context.after(() => { if (previous) { Object.defineProperty(globalThis, 'navigator', previous); } else { delete globalThis.navigator; } });
    assert.equal(await copyBatchReport(reportDialog()), true);
    assert.equal(copied, `${first}=CLEARED=01234567890\n${second}=Ready for Abis\n\nSummary:\nTotal Records: 2\nTotal Successful: 1\nTotal Failed: 1`);
});

test('copying falls back inside the dialog when clipboard access is unavailable', async (context) => {
    const previousNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    const previousDocument = Object.getOwnPropertyDescriptor(globalThis, 'document');
    let field;
    let removed = false;
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {} });
    Object.defineProperty(globalThis, 'document', { configurable: true, value: {
        createElement() { return { style: {}, setAttribute() {}, focus() {}, select() {}, remove() { removed = true; } }; },
        execCommand(command) { assert.equal(command, 'copy'); return true; },
    } });
    context.after(() => {
        for (const [name, descriptor] of [['navigator', previousNavigator], ['document', previousDocument]]) {
            if (descriptor) { Object.defineProperty(globalThis, name, descriptor); } else { delete globalThis[name]; }
        }
    });
    const dialog = reportDialog();
    dialog.appendChild = (element) => { field = element; };
    assert.equal(await copyBatchReport(dialog), true);
    assert.match(field.value, /Summary:/);
    assert.equal(removed, true);
    document.execCommand = () => false;
    assert.equal(await copyBatchReport(dialog), false);
});
