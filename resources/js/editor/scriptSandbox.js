import { createScriptApi } from './scriptApi.js';
import { createScriptTransforms } from './scriptTransforms.js';

// Only the trusted relay runs in the iframe. User code runs in its disposable
// worker, with the opaque origin and inherited CSP denying network access.
function sandboxRelay() {
    let worker;
    const receive = (event) => {
        if (event.source !== parent) return;
        if (event.data?.type === 'stop') {
            worker?.terminate();
            return;
        }
        if (event.data?.type !== 'run' || worker) return;
        try {
            const url = URL.createObjectURL(new Blob([event.data.workerSource], { type: 'text/javascript' }));
            worker = new Worker(url);
            URL.revokeObjectURL(url);
            worker.onmessage = ({ data }) => {
                worker.terminate();
                parent.postMessage({ type: 'result', payload: data }, '*');
            };
            worker.onerror = () => {
                worker.terminate();
                parent.postMessage({ type: 'result', payload: { error: 'Script worker failed.' } }, '*');
            };
            worker.postMessage({ source: event.data.source, values: event.data.values });
        } catch (error) {
            parent.postMessage({ type: 'result', payload: { error: error.message } }, '*');
        }
    };
    addEventListener('message', receive);
    parent.postMessage({ type: 'ready' }, '*');
}

export function runScript(source, { signal, values = {} } = {}) {
    if (source.length > 50000) return Promise.reject(new Error('Script exceeds 50,000 characters.'));
    return new Promise((resolve, reject) => {
        const frame = document.createElement('iframe');
        frame.hidden = true;
        frame.setAttribute('sandbox', 'allow-scripts');
        frame.setAttribute('title', 'Isolated script runtime');
        const nonce = crypto.randomUUID().replaceAll('-', '');
        const workerSource = `const createScriptTransforms = ${createScriptTransforms.toString()};
            const createScriptApi = ${createScriptApi.toString()};
            onmessage = ({ data: { source, values } }) => {
                const send = postMessage.bind(self);
                try {
                    const api = createScriptApi(values);
                    const names = ['part', 'group', 'add', 'log', 'connect', 'repeat', 'mirror', 'parameter'];
                    new Function(...names, '"use strict";\\n' + source)(...names.map(name => api[name]));
                    const result = JSON.stringify(api.result());
                    if (result.length > 250000) throw new Error('Script output exceeds 250 KB.');
                    send({ result });
                } catch (error) { send({ error: String(error.message).slice(0, 2000) }); }
            };`;
        let settled = false;
        const finish = (error, result) => {
            if (settled) return;
            settled = true;
            clearTimeout(timer);
            window.removeEventListener('message', receive);
            signal?.removeEventListener('abort', abort);
            frame.contentWindow?.postMessage({ type: 'stop' }, '*');
            // Give the trusted relay a task to terminate even an infinite loop.
            setTimeout(() => frame.remove(), 50);
            if (error) reject(error);
            else resolve(result);
        };
        const abort = () => finish(new Error('Выполнение отменено.'));
        const receive = (event) => {
            if (event.source !== frame.contentWindow) return;
            if (event.data?.type === 'ready') {
                frame.contentWindow.postMessage({ type: 'run', workerSource, source, values: JSON.parse(JSON.stringify(values)) }, '*');
            } else if (event.data?.type === 'result') {
                const payload = event.data.payload;
                if (payload?.error) return finish(new Error(String(payload.error).slice(0, 2000)));
                try {
                    if (typeof payload?.result !== 'string' || payload.result.length > 250000) throw new Error('Invalid script output.');
                    const result = JSON.parse(payload.result);
                    if (!Array.isArray(result.commands) || result.commands.length > 99 || !Array.isArray(result.logs)) throw new Error('Invalid script output.');
                    finish(null, result);
                } catch (error) { finish(error); }
            }
        };
        const timer = setTimeout(() => finish(new Error('Превышено время выполнения: 3 секунды. Проверьте циклы.')), 3000);
        window.addEventListener('message', receive);
        signal?.addEventListener('abort', abort, { once: true });
        frame.srcdoc = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'nonce-${nonce}' 'unsafe-eval'; worker-src blob:; connect-src 'none'; base-uri 'none'; form-action 'none'"><script nonce="${nonce}">(${sandboxRelay.toString()})()<\/script>`;
        document.body.append(frame);
        if (signal?.aborted) abort();
    });
}
