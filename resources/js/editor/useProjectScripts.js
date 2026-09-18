import axios from 'axios';

export const useProjectScripts = ({ projectId, projects, recordHistory, onApplied }) => {
    const refresh = async () => {
        await Promise.all([projects.fetchProject(projectId), projects.fetchParts(projectId), projects.fetchAssemblyGroups(projectId), projects.fetchProjectConnections(projectId)]);
        await onApplied();
    };
    const replay = async (payload) => {
        const project = await projects.fetchProject(projectId);
        await axios.post(`/projects/${projectId}/script/run`, { ...payload, expected_revision: project.revision });
        await refresh();
    };
    const applyScript = async (payload) => {
        const { data } = await axios.post(`/projects/${projectId}/script/run`, payload);
        const next = structuredClone({ source: payload.source, commands: payload.commands, connections: payload.connections ?? [], parameter_values: payload.parameter_values ?? {} });
        recordHistory({
            label: 'Применение сценария',
            undo: () => replay(data.data.previous),
            redo: () => replay(next),
        });
        await refresh();
        return data.data;
    };
    return { applyScript };
};
