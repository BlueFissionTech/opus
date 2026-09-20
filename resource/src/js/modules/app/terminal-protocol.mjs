export function terminalText(value) {
    return String(value ?? '').replace(/[\u0000-\u0008\u000b-\u001f\u007f-\u009f]/g, '');
}

export function createTerminalProtocol(socket, display) {
    let ready = false;
    let busy = false;
    let pending = null;
    let closed = false;
    const show = value => display(terminalText(value));
    return {
        receive(data) {
            if (closed) { return; }
            let frame;
            try { frame = JSON.parse(data); } catch { this.close('Invalid terminal response.'); return; }
            if (frame?.type === 'ready') {
                ready = true;
                show('Command terminal ready.');
                return;
            }
            if (frame?.type === 'error') {
                this.close(frame.code || 'Terminal unavailable.');
                return;
            }
            if (frame?.type !== 'result' || typeof frame.accepted !== 'boolean'
                || !frame.result || typeof frame.result !== 'object' || Array.isArray(frame.result)) {
                this.close('Invalid terminal response.');
                return;
            }
            busy = false;
            const result = frame.result;
            show(result.output_text);
            show(result.diagnostic_text);
            if (!frame.accepted) { return; }
            if (result.confirmation_required === true) {
                if (typeof result.continuation_token !== 'string' || !result.continuation_token.trim()) {
                    this.close('Invalid terminal confirmation.');
                    return;
                }
                pending = result.continuation_token;
                show('Confirm this action? Type yes or no.');
            } else {
                pending = null;
            }
        },
        submit(input) {
            if (!ready || socket.readyState !== 1) { show('Terminal is not connected.'); return false; }
            if (busy) { show('Wait for the current command.'); return false; }
            let frame;
            if (pending !== null) {
                const decision = input.trim().toLowerCase();
                if (!['yes', 'no'].includes(decision)) { show('Type yes or no for the pending action.'); return false; }
                frame = {type: 'confirm', token: pending, approved: decision === 'yes'};
            } else {
                if (!input.trim()) { return false; }
                frame = {type: 'command', input};
            }
            const encoded = JSON.stringify(frame);
            if (new TextEncoder().encode(encoded).length > 16384) { show('Command exceeds the terminal frame limit.'); return false; }
            try {
                socket.send(encoded);
                busy = true;
                return true;
            } catch {
                this.close('Terminal connection failed.');
                return false;
            }
        },
        close(message = 'Terminal disconnected. No commands will be replayed.') {
            if (closed) { return; }
            closed = true;
            ready = false;
            busy = false;
            pending = null;
            show(message);
            try { socket.close(); } catch { /* The failed transport is already unusable. */ }
        }
    };
}
