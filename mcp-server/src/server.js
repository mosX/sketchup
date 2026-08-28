import process from 'node:process';

const apiUrl = (process.env.SKETCHUP_API_URL ?? 'https://sketchup/api/v1').replace(/\/$/, '');
const apiToken = process.env.SKETCHUP_API_TOKEN ?? '';
const supportedProtocolVersion = '2025-11-25';

const tools = [
    {
        name: 'woodworking_capabilities',
        description: 'Read supported coordinate conventions, woodworking operations, batch commands, and limits before designing a project.',
        inputSchema: { type: 'object', properties: {}, additionalProperties: false },
    },
    {
        name: 'woodworking_list_projects',
        description: 'List projects available to the configured API key.',
        inputSchema: { type: 'object', properties: {}, additionalProperties: false },
    },
    {
        name: 'woodworking_create_project',
        description: 'Create an empty woodworking project and return its initial revision.',
        inputSchema: {
            type: 'object',
            properties: {
                name: { type: 'string', minLength: 1, maxLength: 255 },
                description: { type: 'string', maxLength: 5000 },
            },
            required: ['name'],
            additionalProperties: false,
        },
    },
    {
        name: 'woodworking_get_project',
        description: 'Get a complete project snapshot with definitions, operations, instances, coordinate rules, and current revision.',
        inputSchema: projectIdSchema(),
    },
    {
        name: 'woodworking_validate_project',
        description: 'Validate a project and return warnings, assembly bounds, part count, instance count, and material volume.',
        inputSchema: projectIdSchema(),
    },
    {
        name: 'woodworking_apply_commands',
        description: 'Atomically preview or commit a batch of part and instance commands. Use dry_run=true first, then repeat with dry_run=false and the current revision.',
        inputSchema: {
            type: 'object',
            properties: {
                project_id: { type: 'integer', minimum: 1 },
                expected_revision: { type: 'integer', minimum: 1 },
                dry_run: { type: 'boolean', default: true },
                commands: {
                    type: 'array',
                    minItems: 1,
                    maxItems: 100,
                    items: {
                        type: 'object',
                        properties: {
                            type: { enum: ['create_part', 'create_instance', 'transform_instance', 'delete_instance'] },
                            temporary_id: { type: 'string', maxLength: 100 },
                            part_id: { type: 'integer', minimum: 1 },
                            part_ref: { type: 'string', maxLength: 100 },
                            instance_id: { type: 'integer', minimum: 1 },
                            instance_ref: { type: 'string', maxLength: 100 },
                            data: { type: 'object' },
                        },
                        required: ['type'],
                        additionalProperties: false,
                    },
                },
            },
            required: ['project_id', 'expected_revision', 'commands'],
            additionalProperties: false,
        },
    },
];

let inputBuffer = '';

process.stdin.setEncoding('utf8');
process.stdin.on('data', (chunk) => {
    inputBuffer += chunk;
    processInputBuffer();
});
process.stdin.on('end', () => {
    if (inputBuffer.trim() !== '') {
        handleLine(inputBuffer.trim());
    }
});

function processInputBuffer() {
    let newlineIndex;

    while ((newlineIndex = inputBuffer.indexOf('\n')) >= 0) {
        const line = inputBuffer.slice(0, newlineIndex).trim();
        inputBuffer = inputBuffer.slice(newlineIndex + 1);

        if (line !== '') {
            handleLine(line);
        }
    }
}

async function handleLine(line) {
    let request;

    try {
        request = JSON.parse(line);
    } catch {
        sendError(null, -32700, 'Parse error');
        return;
    }

    if (request.id === undefined) {
        return;
    }

    try {
        const result = await handleRequest(request);
        send({ jsonrpc: '2.0', id: request.id, result });
    } catch (error) {
        sendError(
            request.id,
            Number.isInteger(error?.code) ? error.code : -32603,
            error instanceof Error ? error.message : 'Internal error',
        );
    }
}

async function handleRequest(request) {
    if (request.method === 'initialize') {
        return {
            protocolVersion: supportedProtocolVersion,
            capabilities: { tools: {} },
            serverInfo: {
                name: 'sketchup-woodworking',
                title: 'SketchUp Woodworking Editor',
                version: '0.1.0',
                description: 'Creates and validates woodworking projects through the Laravel API.',
            },
        };
    }

    if (request.method === 'ping') {
        return {};
    }

    if (request.method === 'tools/list') {
        return { tools };
    }

    if (request.method === 'tools/call') {
        return callTool(request.params?.name, request.params?.arguments ?? {});
    }

    const error = new Error(`Method not found: ${request.method}`);
    error.code = -32601;
    throw error;
}

async function callTool(name, args) {
    try {
        const response = await executeTool(name, args);

        return {
            content: [{ type: 'text', text: JSON.stringify(response, null, 2) }],
            structuredContent: response,
        };
    } catch (error) {
        const details = error instanceof ApiError
            ? { status: error.status, response: error.response }
            : { message: error instanceof Error ? error.message : 'Unknown error' };

        return {
            content: [{ type: 'text', text: JSON.stringify(details, null, 2) }],
            isError: true,
        };
    }
}

async function executeTool(name, args) {
    if (name === 'woodworking_capabilities') {
        return apiRequest('/capabilities');
    }

    if (name === 'woodworking_list_projects') {
        return apiRequest('/projects');
    }

    if (name === 'woodworking_create_project') {
        return apiRequest('/projects', { method: 'POST', body: args });
    }

    if (name === 'woodworking_get_project') {
        return apiRequest(`/projects/${integerArgument(args, 'project_id')}`);
    }

    if (name === 'woodworking_validate_project') {
        return apiRequest(`/projects/${integerArgument(args, 'project_id')}/validate`, { method: 'POST' });
    }

    if (name === 'woodworking_apply_commands') {
        const projectId = integerArgument(args, 'project_id');
        const { project_id: ignoredProjectId, ...body } = args;

        return apiRequest(`/projects/${projectId}/commands`, {
            method: 'POST',
            body: { dry_run: true, ...body },
        });
    }

    throw new Error(`Unknown tool: ${name}`);
}

async function apiRequest(path, options = {}) {
    if (apiToken === '') {
        throw new Error('SKETCHUP_API_TOKEN is not configured.');
    }

    const response = await fetch(`${apiUrl}${path}`, {
        method: options.method ?? 'GET',
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${apiToken}`,
            ...(options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
        },
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
        signal: AbortSignal.timeout(30_000),
    });
    const responseBody = await response.json().catch(() => ({ message: 'The API returned a non-JSON response.' }));

    if (!response.ok) {
        throw new ApiError(response.status, responseBody);
    }

    return responseBody;
}

function integerArgument(args, name) {
    const value = Number(args[name]);

    if (!Number.isInteger(value) || value < 1) {
        throw new Error(`${name} must be a positive integer.`);
    }

    return value;
}

function projectIdSchema() {
    return {
        type: 'object',
        properties: { project_id: { type: 'integer', minimum: 1 } },
        required: ['project_id'],
        additionalProperties: false,
    };
}

function send(message) {
    process.stdout.write(`${JSON.stringify(message)}\n`);
}

function sendError(id, code, message) {
    send({ jsonrpc: '2.0', id, error: { code, message } });
}

class ApiError extends Error {
    constructor(status, response) {
        super(`Laravel API request failed with HTTP ${status}.`);
        this.status = status;
        this.response = response;
    }
}
