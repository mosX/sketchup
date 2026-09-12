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
        name: 'woodworking_list_project_templates',
        description: 'List reusable parametric woodworking project templates available to the configured global API key.',
        inputSchema: { type: 'object', properties: {}, additionalProperties: false },
    },
    {
        name: 'woodworking_save_project_template',
        description: 'Capture a complete project as a reusable parametric template.',
        inputSchema: {
            type: 'object',
            properties: {
                project_id: { type: 'integer', minimum: 1 },
                name: { type: 'string', minLength: 1, maxLength: 255 },
                description: { type: 'string', maxLength: 5000 },
            },
            required: ['project_id', 'name'],
            additionalProperties: false,
        },
    },
    {
        name: 'woodworking_instantiate_project_template',
        description: 'Create a project from a template with independent X width, Y depth, and Z height dimensions.',
        inputSchema: {
            type: 'object',
            properties: {
                template_id: { type: 'integer', minimum: 1 },
                name: { type: 'string', minLength: 1, maxLength: 255 },
                description: { type: 'string', maxLength: 5000 },
                width: { type: 'number', minimum: 1, maximum: 100000 },
                depth: { type: 'number', minimum: 1, maximum: 100000 },
                height: { type: 'number', minimum: 1, maximum: 100000 },
            },
            required: ['template_id', 'name', 'width', 'depth', 'height'],
            additionalProperties: false,
        },
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
        name: 'woodworking_analyze_project',
        description: 'Get rotated-bounds diagnostics, review items, a bill of materials, and preliminary linear and sheet cutting maps.',
        inputSchema: {
            type: 'object',
            properties: {
                project_id: { type: 'integer', minimum: 1 },
                kerf_mm: { type: 'number', minimum: 0, maximum: 20 },
                edge_margin_mm: { type: 'number', minimum: 0, maximum: 500 },
                linear_stock_length_mm: { type: 'number', minimum: 100, maximum: 50000 },
                sheet_length_mm: { type: 'number', minimum: 100, maximum: 10000 },
                sheet_width_mm: { type: 'number', minimum: 100, maximum: 10000 },
            },
            required: ['project_id'],
            additionalProperties: false,
        },
    },
    {
        name: 'woodworking_list_connections',
        description: 'List semantic woodworking connections between project instances.',
        inputSchema: projectIdSchema(),
    },
    {
        name: 'woodworking_create_connection',
        description: 'Connect two instances with a butt, half-lap, mortise-and-tenon, or dowel joint.',
        inputSchema: {
            type: 'object',
            properties: {
                project_id: { type: 'integer', minimum: 1 },
                primary_instance_id: { type: 'integer', minimum: 1 },
                secondary_instance_id: { type: 'integer', minimum: 1 },
                type: { enum: ['butt', 'half_lap', 'mortise_tenon', 'dowel'] },
                label: { type: 'string', maxLength: 255 },
                note: { type: 'string', maxLength: 5000 },
                parameters: { type: 'object', additionalProperties: true },
            },
            required: ['project_id', 'primary_instance_id', 'secondary_instance_id', 'type'],
            additionalProperties: false,
        },
    },
    {
        name: 'woodworking_update_connection',
        description: 'Update connection metadata or face-local placement parameters. Changing placement marks generated machining as outdated.',
        inputSchema: {
            type: 'object',
            properties: {
                project_id: { type: 'integer', minimum: 1 },
                connection_id: { type: 'integer', minimum: 1 },
                label: { type: ['string', 'null'], maxLength: 255 },
                note: { type: ['string', 'null'], maxLength: 5000 },
                is_verified: { type: 'boolean' },
                parameters: {
                    type: 'object',
                    properties: {
                        primary_face: { enum: ['top', 'bottom', 'left', 'right', 'start', 'end'] },
                        secondary_face: { enum: ['top', 'bottom', 'left', 'right', 'start', 'end'] },
                        primary_center_u: { type: 'number', minimum: 0, maximum: 100000 },
                        primary_center_v: { type: 'number', minimum: 0, maximum: 100000 },
                        secondary_center_u: { type: 'number', minimum: 0, maximum: 100000 },
                        secondary_center_v: { type: 'number', minimum: 0, maximum: 100000 },
                        joint_angle: { type: 'number', minimum: -180, maximum: 180 },
                        joint_length: { type: 'number', minimum: 0.1, maximum: 100000 },
                        joint_width: { type: 'number', minimum: 0.1, maximum: 10000 },
                        depth_ratio: { type: 'number', minimum: 0.1, maximum: 0.9 },
                        tenon_width: { type: 'number', minimum: 0.1, maximum: 10000 },
                        tenon_thickness: { type: 'number', minimum: 0.1, maximum: 10000 },
                        tenon_length: { type: 'number', minimum: 0.1, maximum: 10000 },
                        dowel_diameter: { type: 'number', minimum: 0.1, maximum: 100 },
                        dowel_count: { type: 'integer', minimum: 1, maximum: 100 },
                        dowel_depth: { type: 'number', minimum: 0.1, maximum: 1000 },
                        dowel_spacing: { type: 'number', minimum: 0.1, maximum: 10000 },
                    },
                    additionalProperties: false,
                },
            },
            required: ['project_id', 'connection_id'],
            additionalProperties: false,
        },
    },
    {
        name: 'woodworking_delete_connection',
        description: 'Delete a woodworking connection from a project.',
        inputSchema: {
            type: 'object',
            properties: {
                project_id: { type: 'integer', minimum: 1 },
                connection_id: { type: 'integer', minimum: 1 },
            },
            required: ['project_id', 'connection_id'],
            additionalProperties: false,
        },
    },
    {
        name: 'woodworking_generate_connection_machining',
        description: 'Generate applied machining operations for both parts of a semantic connection. Shared part definitions are split automatically.',
        inputSchema: {
            type: 'object',
            properties: {
                project_id: { type: 'integer', minimum: 1 },
                connection_id: { type: 'integer', minimum: 1 },
            },
            required: ['project_id', 'connection_id'],
            additionalProperties: false,
        },
    },
    {
        name: 'woodworking_remove_connection_machining',
        description: 'Remove operations generated by a connection and return it to pending machining state.',
        inputSchema: {
            type: 'object',
            properties: {
                project_id: { type: 'integer', minimum: 1 },
                connection_id: { type: 'integer', minimum: 1 },
            },
            required: ['project_id', 'connection_id'],
            additionalProperties: false,
        },
    },
    {
        name: 'woodworking_apply_commands',
        description: 'Atomically preview or commit a batch of part, assembly-group, and instance commands. Use dry_run=true first, then repeat with dry_run=false and the current revision.',
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
                            type: {
                                enum: [
                                    'create_part',
                                    'create_instance',
                                    'transform_instance',
                                    'delete_instance',
                                    'create_group',
                                    'update_group',
                                    'delete_group',
                                    'assign_instance_to_group',
                                ],
                            },
                            temporary_id: { type: 'string', maxLength: 100 },
                            part_id: { type: 'integer', minimum: 1 },
                            part_ref: { type: 'string', maxLength: 100 },
                            instance_id: { type: 'integer', minimum: 1 },
                            instance_ref: { type: 'string', maxLength: 100 },
                            group_id: { type: 'integer', minimum: 1 },
                            group_ref: { type: 'string', maxLength: 100 },
                            parent_group_id: { type: 'integer', minimum: 1 },
                            parent_group_ref: { type: 'string', maxLength: 100 },
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

    if (name === 'woodworking_list_project_templates') {
        return apiRequest('/project-templates');
    }

    if (name === 'woodworking_save_project_template') {
        return apiRequest('/project-templates', { method: 'POST', body: args });
    }

    if (name === 'woodworking_instantiate_project_template') {
        const templateId = integerArgument(args, 'template_id');
        const { template_id: ignoredTemplateId, width, depth, height, ...project } = args;

        return apiRequest(`/project-templates/${templateId}/instantiate`, {
            method: 'POST',
            body: { ...project, dimensions: { width, depth, height } },
        });
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

    if (name === 'woodworking_analyze_project') {
        const projectId = integerArgument(args, 'project_id');
        const query = new URLSearchParams(Object.entries(args)
            .filter(([key, value]) => key !== 'project_id' && value !== undefined)
            .map(([key, value]) => [key, String(value)]));

        return apiRequest(`/projects/${projectId}/analysis${query.size ? `?${query}` : ''}`);
    }

    if (name === 'woodworking_list_connections') {
        return apiRequest(`/projects/${integerArgument(args, 'project_id')}/connections`);
    }

    if (name === 'woodworking_create_connection') {
        const projectId = integerArgument(args, 'project_id');
        const { project_id: ignoredProjectId, ...body } = args;

        return apiRequest(`/projects/${projectId}/connections`, { method: 'POST', body });
    }

    if (name === 'woodworking_update_connection') {
        const projectId = integerArgument(args, 'project_id');
        const connectionId = integerArgument(args, 'connection_id');
        const { project_id: ignoredProjectId, connection_id: ignoredConnectionId, ...body } = args;

        return apiRequest(`/projects/${projectId}/connections/${connectionId}`, { method: 'PATCH', body });
    }

    if (name === 'woodworking_delete_connection') {
        const projectId = integerArgument(args, 'project_id');
        const connectionId = integerArgument(args, 'connection_id');

        return apiRequest(`/projects/${projectId}/connections/${connectionId}`, { method: 'DELETE' });
    }

    if (name === 'woodworking_generate_connection_machining') {
        const projectId = integerArgument(args, 'project_id');
        const connectionId = integerArgument(args, 'connection_id');

        return apiRequest(`/projects/${projectId}/connections/${connectionId}/machining`, { method: 'POST' });
    }

    if (name === 'woodworking_remove_connection_machining') {
        const projectId = integerArgument(args, 'project_id');
        const connectionId = integerArgument(args, 'connection_id');

        return apiRequest(`/projects/${projectId}/connections/${connectionId}/machining`, { method: 'DELETE' });
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
