import { copyText } from './clipboard.js';

export function formatSelectedRows(rows, selectedRows) {
    const selected = new Set(selectedRows.map(String));

    return rows
        .filter(({ id }) => selected.has(String(id)))
        .map(({ inputs }) => inputs
            .filter(({ value }) => value !== '')
            .map(({ label, value, preserveCase }) => `${label.toUpperCase()}: ${preserveCase ? value : value.toUpperCase()}`)
            .join('\n'))
        .filter(Boolean)
        .join('\n\n');
}

export function serviceRequestCopy() {
    return {
        selectedRows: [],
        copyStatus: '',
        copyQueue: Promise.resolve(),
        selectAll() {
            this.selectedRows = Array.from(this.$root.querySelectorAll('[data-copy-row]')).map((row) => row.dataset.copyRow);
            return this.copySelected();
        },
        clearSelection() {
            this.selectedRows = [];
            this.copyStatus = '';
        },
        async copySelected() {
            const rows = Array.from(this.$root.querySelectorAll('[data-copy-row]')).map((row) => ({
                id: row.dataset.copyRow,
                inputs: Array.from(row.querySelectorAll('[data-copy-field]')).map((field) => ({
                    label: field.dataset.copyLabel,
                    value: field.dataset.copyValue,
                    preserveCase: field.dataset.copyPreserveCase === 'true',
                })),
            }));
            const text = formatSelectedRows(rows, this.selectedRows);
            this.copyStatus = '';
            if (text === '') {
                return;
            }
            this.copyQueue = this.copyQueue.catch(() => {}).then(async () => {
                const copied = await copyText(text, this.$root);
                this.copyStatus = this.selectedRows.length === 0 ? '' : (copied
                    ? 'Selected request inputs copied.'
                    : 'Copy failed. Use Copy selected rows to try again.');
            });
            return this.copyQueue;
        },
    };
}
