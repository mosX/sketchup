import axios from 'axios';
import { defineStore } from 'pinia';

export const useProjectsStore = defineStore('projects', {
    state: () => ({
        items: [],
        activeProject: null,
        parts: [],
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

            for (const part of this.parts) {
                part.instances = part.instances.filter((instance) => instance.id !== instanceId);
                part.instance_count = part.instances.length;
            }
        },
    },
});
