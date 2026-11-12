export async function copyText(text, container) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const previousFocus = document.activeElement;
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        container.appendChild(field);
        try {
            field.focus();
            field.select();
            return document.execCommand('copy');
        } catch {
            return false;
        } finally {
            field.remove();
            previousFocus?.focus?.();
        }
    }
}
