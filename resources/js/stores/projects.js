import axios from 'axios';
import { defineStore } from 'pinia';

export const useProjectsStore = defineStore('projects', {
    state: () => ({
        items: [],
        activeProject: null,
        parts: [],
        assemblyGroups: [],
        templates: [],
        connections: [],
        loading: false,
    }),

    actions: {
        async fetchProjects() {
            this.loading = true;

            try {
                const { data } = await axios.get('/projects');
                this.items = data.data;
            } finally {
                this.loading = false;
            }
        },

        async fetchProjectTemplates() {
            const { data } = await axios.get('/project-templates');
            this.templates = data.data;

            return this.templates;
        },

        async createProjectTemplate(payload) {
            const { data } = await axios.post('/project-templates', payload);
            this.templates.unshift(data.data);

            return data.data;
        },

        async instantiateProjectTemplate(templateId, payload) {
            const { data } = await axios.post(`/project-templates/${templateId}/instantiate`, payload);
            this.items.unshift(data.data);

            return data.data;
        },

        async deleteProjectTemplate(templateId) {
            await axios.delete(`/project-templates/${templateId}`);
            this.templates = this.templates.filter((template) => template.id !== templateId);
        },

        async fetchProject(id) {
            const { data } = await axios.get(`/projects/${id}`);
            this.activeProject = data.data;

            return this.activeProject;
        },

        async createProject(payload) {
            const { data } = await axios.post('/projects', payload);
            this.items.unshift(data.data);

            return data.data;
        },

        async updateProject(id, payload) {
            const { data } = await axios.patch(`/projects/${id}`, payload);
            this.activeProject = data.data;
            const index = this.items.findIndex((project) => project.id === data.data.id);

            if (index !== -1) {
                this.items[index] = data.data;
            }

            return data.data;
        },

        async deleteProject(id) {
            await axios.delete(`/projects/${id}`);
            this.items = this.items.filter((project) => project.id !== id);
        },

        async fetchParts(projectId) {
            const { data } = await axios.get(`/projects/${projectId}/parts`);
            this.parts = data.data;

            return this.parts;
        },

        async fetchAssemblyGroups(projectId) {
            const { data } = await axios.get(`/projects/${projectId}/assembly-groups`);
            this.assemblyGroups = data.data;

            return this.assemblyGroups;
        },

        async fetchProjectConnections(projectId) {
            const { data } = await axios.get(`/projects/${projectId}/connections`);
            this.connections = data.data;

            return this.connections;
        },

        async createProjectConnection(projectId, payload) {
            const { data } = await axios.post(`/projects/${projectId}/connections`, payload);
            this.connections.unshift(data.data);

            return data.data;
        },

        async updateProjectConnection(projectId, connectionId, payload) {
            const { data } = await axios.patch(`/projects/${projectId}/connections/${connectionId}`, payload);
            const index = this.connections.findIndex((connection) => connection.id === connectionId);

            if (index !== -1) this.connections[index] = data.data;

            return data.data;
        },

        async generateProjectConnectionMachining(projectId, connectionId) {
            const { data } = await axios.post(`/projects/${projectId}/connections/${connectionId}/machining`);
            const index = this.connections.findIndex((connection) => connection.id === connectionId);

            if (index !== -1) this.connections[index] = data.data;

            return data.data;
        },

        async removeProjectConnectionMachining(projectId, connectionId) {
            const { data } = await axios.delete(`/projects/${projectId}/connections/${connectionId}/machining`);
            const index = this.connections.findIndex((connection) => connection.id === connectionId);

            if (index !== -1) this.connections[index] = data.data;

            return data.data;
        },

        async deleteProjectConnection(projectId, connectionId) {
            await axios.delete(`/projects/${projectId}/connections/${connectionId}`);
            this.connections = this.connections.filter((connection) => connection.id !== connectionId);
        },

        async fetchProjectAnalysis(projectId, settings = {}) {
            const { data } = await axios.get(`/projects/${projectId}/analysis`, { params: settings });

            return data.data;
        },

        async createAssemblyGroup(projectId, payload) {
            const { data } = await axios.post(`/projects/${projectId}/assembly-groups`, payload);
            this.assemblyGroups.push(data.data);

            return data.data;
        },

        async updateAssemblyGroup(projectId, groupId, payload) {
            const { data } = await axios.patch(`/projects/${projectId}/assembly-groups/${groupId}`, payload);
            const index = this.assemblyGroups.findIndex((group) => group.id === groupId);

            if (index !== -1) this.assemblyGroups[index] = data.data;

            return data.data;
        },

        async deleteAssemblyGroup(projectId, groupId, deleteContents = false) {
            await axios.delete(`/projects/${projectId}/assembly-groups/${groupId}`, { data: { delete_contents: deleteContents } });
            await Promise.all([this.fetchAssemblyGroups(projectId), this.fetchParts(projectId)]);
        },

        async createPart(projectId, payload) {
            const { data } = await axios.post(`/projects/${projectId}/parts`, payload);
            this.parts.unshift(data.data);

            return data.data;
        },

        async updatePart(projectId, partId, payload) {
            const { data } = await axios.patch(`/projects/${projectId}/parts/${partId}`, payload);
            const index = this.parts.findIndex((part) => part.id === data.data.id);

            if (index !== -1) {
                this.parts[index] = data.data;
            }

            return data.data;
        },

        async deletePart(projectId, partId) {
            await axios.delete(`/projects/${projectId}/parts/${partId}`);
            this.parts = this.parts.filter((part) => part.id !== partId);
        },

        async createInstances(projectId, partId, payload) {
            const { data } = await axios.post(`/projects/${projectId}/parts/${partId}/instances`, payload);
            const part = this.parts.find((item) => item.id === partId);

            if (part) {
                part.instances.push(...data.data);
                part.instance_count = part.instances.length;
            }

            return data.data;
        },

        async updateInstance(projectId, instanceId, payload) {
            const { data } = await axios.patch(`/projects/${projectId}/instances/${instanceId}`, payload);

            for (const part of this.parts) {
                const index = part.instances.findIndex((instance) => instance.id === instanceId);

                if (index !== -1) {
                    part.instances[index] = data.data;
                    break;
                }
            }

            return data.data;
        },

        async deleteInstance(projectId, instanceId) {
            await axios.delete(`/projects/${projectId}/instances/${instanceId}`);
            this.connections = this.connections.filter((connection) => (
                connection.primary_instance_id !== instanceId && connection.secondary_instance_id !== instanceId
            ));

            for (const part of this.parts) {
                part.instances = part.instances.filter((instance) => instance.id !== instanceId);
                part.instance_count = part.instances.length;
            }
        },
    },
});
