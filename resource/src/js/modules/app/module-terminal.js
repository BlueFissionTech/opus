import { Terminal } from 'xterm';
import { FitAddon } from 'xterm-addon-fit';
import { createTerminalProtocol, terminalText } from './terminal-protocol.mjs';

const ModuleTerminal = {
    init() {
        const element = document.getElementById('terminal');
        if (!element || element.dataset.initialized === 'true') { return; }
        element.dataset.initialized = 'true';
        const terminal = new Terminal();
        const fit = new FitAddon();
        terminal.loadAddon(fit);
        terminal.open(element);
        fit.fit();
        let line = '';
        const display = text => {
            if (text) { terminal.write('\r\n' + text.replace(/\n/g, '\r\n')); }
            terminal.write('\r\nuser> ');
        };
        const endpoint = element.dataset.endpoint || `${location.protocol === 'https:' ? 'wss:' : 'ws:'}//${location.host}/terminal`;
        let socket;
        try { socket = new WebSocket(endpoint); } catch {
            display('Terminal endpoint is unavailable.');
            return;
        }
        const protocol = createTerminalProtocol(socket, display);
        socket.addEventListener('message', event => protocol.receive(event.data));
        socket.addEventListener('close', () => protocol.close());
        socket.addEventListener('error', () => protocol.close('Terminal connection failed.'));
        terminal.writeln('Connecting to the command terminal...');
        const input = document.getElementById('terminal-input');
        if (input) { input.disabled = true; }
        terminal.onData(data => {
            if (data === '\r') {
                terminal.write('\r\n');
                protocol.submit(line);
                line = '';
                return;
            }
            if (data === '\x7f') {
                if (line.length) { line = Array.from(line).slice(0, -1).join(''); terminal.write('\b \b'); }
                return;
            }
            if (data.startsWith('\x1b')) { return; }
            const text = terminalText(data).replace(/[\r\n\t]/g, ' ');
            if (line.length + text.length > 16384) { return; }
            line += text;
            terminal.write(text);
        });
        element.addEventListener('click', () => terminal.focus());
        window.addEventListener('pagehide', () => {
            protocol.close();
            terminal.dispose();
            delete element.dataset.initialized;
        }, {once: true});
        if (typeof app !== 'undefined') { app.assign('connection_state', () => socket.readyState); }
        terminal.focus();
    }
};

jQuery(document).ready(() => ModuleTerminal.init());
export default ModuleTerminal;
