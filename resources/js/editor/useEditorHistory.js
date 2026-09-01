import { computed, ref } from 'vue';

export const useEditorHistory = (limit = 100) => {
    const undoStack = ref([]);
    const redoStack = ref([]);
    const busy = ref(false);
    const canUndo = computed(() => !busy.value && undoStack.value.length > 0);
    const canRedo = computed(() => !busy.value && redoStack.value.length > 0);
    const undoLabel = computed(() => undoStack.value.at(-1)?.label ?? 'Отменить');
    const redoLabel = computed(() => redoStack.value.at(-1)?.label ?? 'Повторить');

    const record = (command) => {
        undoStack.value.push(command);

        if (undoStack.value.length > limit) {
            undoStack.value.shift();
        }

        redoStack.value = [];
    };

    const undo = async () => {
        if (!canUndo.value) return;

        const command = undoStack.value.pop();
        busy.value = true;

        try {
            await command.undo();
            redoStack.value.push(command);
        } catch (error) {
            undoStack.value.push(command);
            throw error;
        } finally {
            busy.value = false;
        }
    };

    const redo = async () => {
        if (!canRedo.value) return;

        const command = redoStack.value.pop();
        busy.value = true;

        try {
            await command.redo();
            undoStack.value.push(command);
        } catch (error) {
            redoStack.value.push(command);
            throw error;
        } finally {
            busy.value = false;
        }
    };

    const clear = () => {
        undoStack.value = [];
        redoStack.value = [];
    };

    return { busy, canRedo, canUndo, clear, record, redo, redoLabel, undo, undoLabel };
};
