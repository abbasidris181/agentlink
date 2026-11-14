export function notificationBatches(wire, delayMs = 0, pause = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds))) {
    return {
        busy: false,
        interrupted: false,
        async start() {
            if (this.busy) return;
            this.busy = true;
            this.interrupted = false;
            try {
                let result = await wire.confirmSending();
                while (result.status === 'ready') {
                    result = await wire.sendNextBatch();
                    if (result.status === 'ready' && delayMs > 0) {
                        await pause(delayMs);
                    }
                }
            } catch {
                this.interrupted = true;
                try {
                    await wire.stopInterrupted();
                } catch {
                    // Reloading reconciles the durable operation; never retry a send.
                }
            } finally {
                this.busy = false;
            }
        },
    };
}
