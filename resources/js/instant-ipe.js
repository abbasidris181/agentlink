import { copyText } from './clipboard.js';

export function formatInstantIpeInput(value, bulk, preserveTrailingSeparator = false) {
    const uppercase = value.replace(/[a-z]/g, (letter) => letter.toUpperCase());

    if (!bulk) {
        return uppercase;
    }

    const tokens = uppercase.split(/[\s,;]+/).filter(Boolean);
    const lines = tokens.flatMap((token) => /^[A-Z0-9]+$/.test(token)
        ? token.match(/.{1,15}/g)
        : [token]);
    const formatted = lines.join('\n');

    return formatted && preserveTrailingSeparator && /[\s,;]$/.test(value)
        ? `${formatted}\n`
        : formatted;
}

export function formatInstantIpeField(field, bulk) {
    const original = field.value;
    const start = field.selectionStart ?? original.length;
    const end = field.selectionEnd ?? start;
    const formatted = formatInstantIpeInput(original, bulk, true);
    const newStart = formatInstantIpeInput(original.slice(0, start), bulk, true).length;
    const newEnd = formatInstantIpeInput(original.slice(0, end), bulk, true).length;

    field.value = formatted;
    field.setSelectionRange(Math.min(newStart, formatted.length), Math.min(newEnd, formatted.length));

    return formatted;
}

export async function copyBatchReport(dialog) {
    const report = dialog.querySelector('[data-report-lines]').textContent;
    const summary = Array.from(dialog.querySelectorAll('[data-report-summary] > div'))
        .map((row) => Array.from(row.children).map((cell) => cell.textContent.trim()).join(' '));
    const text = `${report}\n\nSummary:\n${summary.join('\n')}`;

    return copyText(text, dialog);
}
