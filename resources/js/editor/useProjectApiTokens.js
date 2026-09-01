import axios from 'axios';
import { computed, ref } from 'vue';

export const useProjectApiTokens = (projectId) => {
    const showAiAccess = ref(false);
    const apiTokens = ref([]);
    const apiTokensLoading = ref(false);
    const apiTokenError = ref('');
    const createdApiToken = ref('');
    const tokenCopied = ref(false);
    const apiTokenName = ref('Woodworking AI agent');
    const apiTokenExpiresInDays = ref(30);
    const projectApiTokens = computed(() => apiTokens.value.filter((token) => token.abilities.includes(`project:${projectId}`)));

    const loadApiTokens = async () => {
        const { data } = await axios.get('/v1/api-tokens');
        apiTokens.value = data.data;
    };

    const openAiAccess = async () => {
        showAiAccess.value = true;
        createdApiToken.value = '';
        tokenCopied.value = false;
        apiTokenError.value = '';

        try {
            await loadApiTokens();
        } catch (error) {
            apiTokenError.value = error.response?.data?.message ?? 'Не удалось загрузить AI-ключи.';
        }
    };

    const createApiToken = async () => {
        apiTokensLoading.value = true;
        apiTokenError.value = '';
        tokenCopied.value = false;

        try {
            const { data } = await axios.post('/v1/api-tokens', {
                name: apiTokenName.value,
                abilities: ['projects:read', 'projects:write'],
                project_id: projectId,
                expires_in_days: apiTokenExpiresInDays.value,
            });
            createdApiToken.value = data.token;
            await loadApiTokens();
        } catch (error) {
            apiTokenError.value = error.response?.data?.message ?? 'Не удалось создать AI-ключ.';
        } finally {
            apiTokensLoading.value = false;
        }
    };

    const copyApiToken = async () => {
        await navigator.clipboard.writeText(createdApiToken.value);
        tokenCopied.value = true;
    };

    const revokeApiToken = async (tokenId) => {
        await axios.delete(`/v1/api-tokens/${tokenId}`);
        apiTokens.value = apiTokens.value.filter((token) => token.id !== tokenId);
    };

    return {
        apiTokenError,
        apiTokenExpiresInDays,
        apiTokenName,
        apiTokensLoading,
        copyApiToken,
        createApiToken,
        createdApiToken,
        openAiAccess,
        projectApiTokens,
        revokeApiToken,
        showAiAccess,
        tokenCopied,
    };
};
