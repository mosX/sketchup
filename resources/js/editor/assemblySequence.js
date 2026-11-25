const linkedInstances = (fastener) => [fastener.source_instance_id, fastener.target_instance_id].filter(id => id != null);

export const planAssemblySequence = (steps, fasteners) => {
    const firstStep = new Map();
    const installed = new Set();
    const explicit = new Set();
    const hardware = steps.map((step, index) => {
        for (const id of step.instance_ids) {
            if (!firstStep.has(id)) firstStep.set(id, index);
        }
        for (const id of step.fastener_ids ?? []) explicit.add(id);
        return [...new Set(step.fastener_ids ?? [])];
    });
    let nextAutomaticStep = -1;
    const automaticAtOrAfter = [];
    for (let index = steps.length - 1; index >= 0; index--) {
        if (steps[index].fastener_ids == null) nextAutomaticStep = index;
        automaticAtOrAfter[index] = nextAutomaticStep;
    }
    for (const item of fasteners) {
        if (explicit.has(item.id)) continue;
        const readiness = linkedInstances(item).map(id => firstStep.get(id));
        if (readiness.some(index => index === undefined)) continue;
        const index = automaticAtOrAfter[Math.max(...readiness)];
        if (index >= 0) hardware[index].push(item.id);
    }
    for (const ids of hardware) {
        for (const id of ids) installed.add(id);
    }
    return { firstStep, hardware, installed };
};

export const checkAssemblySequence = (steps, instances, fasteners, plan = planAssemblySequence(steps, fasteners)) => {
    if (!steps.length) return { status: 'no_steps', step_count: 0, issue_count: 0, issues: [] };

    const { firstStep, installed } = plan;
    const byInstance = new Map(instances.map(item => [item.id, item]));
    const byFastener = new Map(fasteners.map(item => [item.id, item]));
    const issues = [];
    const add = (code, stepIndex, instanceIds, fastener = null, extra = {}) => {
        issues.push({
            code,
            key: [code, stepIndex, fastener?.id ?? instanceIds[0], extra.reference_id ?? ''].join(':'),
            step_index: stepIndex,
            context: {
                instance_ids: instanceIds.filter(id => byInstance.has(id)),
                instance_names: instanceIds.map(id => byInstance.get(id)?.name).filter(Boolean),
                fastener_ids: fastener ? [fastener.id] : [],
                ...extra,
            },
        });
    };

    for (const [index, step] of steps.entries()) {
        for (const id of new Set([...step.instance_ids, ...(step.highlight_instance_ids ?? [])])) {
            if (!byInstance.has(id)) add('deleted_instance', index, [], null, { reference_id: id });
        }
        for (const id of new Set(step.fastener_ids ?? [])) {
            const fastener = byFastener.get(id);
            if (!fastener) {
                add('deleted_fastener', index, [], null, { reference_id: id });
                continue;
            }
            const endpoints = linkedInstances(fastener);
            const missing = endpoints.filter(instanceId => !firstStep.has(instanceId) || firstStep.get(instanceId) > index);
            if (missing.length) {
                const laterSteps = missing.map(instanceId => firstStep.get(instanceId));
                add('fastener_before_parts', index, endpoints, fastener, {
                    missing_instance_ids: missing,
                    earliest_step_index: laterSteps.includes(undefined) ? null : Math.max(...laterSteps),
                });
            }
        }
    }

    for (const fastener of fasteners) {
        const endpoints = linkedInstances(fastener);
        if (endpoints.length !== 2 || installed.has(fastener.id) || endpoints.some(id => !firstStep.has(id))) continue;
        add('missing_fastener', Math.max(...endpoints.map(id => firstStep.get(id))), endpoints, fastener);
    }
    for (const instance of instances) {
        if (!firstStep.has(instance.id)) add('missing_instance', null, [instance.id]);
    }

    return { status: issues.length ? 'issues' : 'clear', step_count: steps.length, issue_count: issues.length, issues };
};
