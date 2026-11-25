import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:http';
import { createReadStream, existsSync, readFileSync, statSync } from 'node:fs';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

const input = JSON.parse(readFileSync(0, 'utf8'));
const local = '127.0.0.1';
const browserLocations = process.platform === 'win32' ? [
    ...[process.env.ProgramFiles, process.env['ProgramFiles(x86)'], process.env.LOCALAPPDATA].filter(Boolean).flatMap(root => [
        join(root, 'Google/Chrome/Application/chrome.exe'), join(root, 'Microsoft/Edge/Application/msedge.exe'),
    ]),
] : [
    '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];
const binary = process.env.WOODWORKING_CHROMIUM_BINARY || browserLocations.find(existsSync);
if (!binary) throw new Error('Chromium is required. Configure WOODWORKING_CHROMIUM_BINARY.');

const imports = {
    three: '/node_modules/three/build/three.module.js',
    'three/addons/': '/node_modules/three/examples/jsm/',
    'three/examples/jsm/': '/node_modules/three/examples/jsm/',
    'three-bvh-csg': '/node_modules/three-bvh-csg/src/index.js',
    'three-mesh-bvh': '/node_modules/three-mesh-bvh/src/index.js',
};
const nonce = randomBytes(16).toString('base64');
const html = `<!DOCTYPE html><meta charset="utf-8"><script type="importmap" nonce="${nonce}">${JSON.stringify({ imports })}</script>
<script type="module" src="/resources/js/editor/projectExportPage.js"></script>`;
const server = createServer((request, response) => {
    const path = new URL(request.url, `http://${local}`).pathname;
    if (path === '/') {
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.setHeader('Content-Security-Policy', `default-src 'none'; script-src 'self' 'nonce-${nonce}'; img-src 'self' data: blob:`);
        return response.end(html);
    }
    const allowed = /^(?:\/resources\/js\/editor\/[\w/-]+\.js|\/resources\/images\/presentation-[a-z-]+\.jpg|\/node_modules\/(?:three|three-bvh-csg|three-mesh-bvh)\/[\w/.-]+\.js)$/.test(path);
    const file = resolve('.' + path);
    if (!allowed || !statSync(file, { throwIfNoEntry: false })?.isFile()) {
        response.statusCode = 404;
        return response.end();
    }
    response.setHeader('Content-Type', path.endsWith('.jpg') ? 'image/jpeg' : 'text/javascript; charset=utf-8');
    createReadStream(file).pipe(response);
});

const until = async (read, deadline, message) => {
    while (Date.now() < deadline) {
        const value = await read();
        if (value) return value;
        await delay(75);
    }
    throw new Error(message);
};

const profile = await mkdtemp(join(tmpdir(), 'woodworking-export-'));
let browser;
let socket;
try {
    await new Promise((resolve, reject) => server.once('error', reject).listen(0, local, resolve));
    browser = spawn(binary, [
        '--headless=new', '--no-first-run', '--no-default-browser-check', '--disable-background-networking',
        '--remote-debugging-port=0', `--remote-debugging-address=${local}`, `--user-data-dir=${profile}`,
        '--use-angle=swiftshader', '--enable-unsafe-swiftshader', 'about:blank',
    ], { windowsHide: true, stdio: ['ignore', 'ignore', 'ignore'] });
    let browserError;
    browser.on('error', error => { browserError = error; });
    const deadline = Date.now() + 12000;
    const port = await until(async () => {
        if (browserError) throw browserError;
        if (browser.exitCode !== null) throw new Error('Chromium exited during startup.');
        const info = await readFile(join(profile, 'DevToolsActivePort'), 'utf8').catch(() => '');
        return /^\d+/.exec(info)?.[0];
    }, deadline, 'Chromium did not start in time.');
    const pages = await fetch(`http://${local}:${port}/json/list`, { signal: AbortSignal.timeout(3000) }).then(response => response.json());
    const page = pages.find(target => target.type === 'page');
    const address = new URL(page.webSocketDebuggerUrl);
    if (address.hostname !== local || address.port !== port) throw new Error('Invalid local Chromium address.');
    socket = new WebSocket(address);
    await new Promise((resolve, reject) => {
        socket.addEventListener('open', resolve, { once: true });
        socket.addEventListener('error', reject, { once: true });
    });

    let nextId = 0;
    const command = (method, params = {}, timeout = 5000) => new Promise((resolve, reject) => {
        const id = ++nextId;
        const done = (event) => {
            const data = JSON.parse(event.data);
            if (data.id !== id) return;
            clearTimeout(timer);
            socket.removeEventListener('message', done);
            if (data.error) reject(new Error(data.error.message));
            else resolve(data.result);
        };
        const timer = setTimeout(() => {
            socket.removeEventListener('message', done);
            reject(new Error(`Chromium timed out during ${method}.`));
        }, timeout);
        socket.addEventListener('message', done);
        socket.send(JSON.stringify({ id, method, params }));
    });
    await command('Page.navigate', { url: `http://${local}:${server.address().port}/` });
    await until(async () => {
        const value = await command('Runtime.evaluate', { expression: 'typeof window.exportWoodworkingProject === "function"' });
        return value.result?.value;
    }, Date.now() + 8000, 'Export renderer did not initialize.');
    const rendered = await command('Runtime.evaluate', {
        expression: `window.exportWoodworkingProject(${JSON.stringify(input)})`,
        awaitPromise: true,
        returnByValue: true,
    }, 25000);
    if (rendered.exceptionDetails) throw new Error(rendered.exceptionDetails.exception?.description || 'Rendering failed.');
    if (typeof rendered.result?.value !== 'string') throw new Error('Export produced no file.');
    process.stdout.write(Buffer.from(rendered.result.value, 'base64'));
} finally {
    socket?.close();
    if (browser && browser.exitCode === null && browser.pid) {
        browser.kill();
        await Promise.race([new Promise(resolve => browser.once('exit', resolve)), delay(1000)]);
    }
    server.closeAllConnections();
    server.close();
    await rm(profile, { recursive: true, force: true, maxRetries: 2 }).catch(() => {});
}
