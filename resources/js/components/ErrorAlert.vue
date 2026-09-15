<script setup lang="ts">
defineProps<{ message: string; title?: string; retryable?: boolean }>();
defineEmits<{ retry: [] }>();
</script>

<template>
    <div role="alert" class="flex items-start justify-between gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <div>
            <p v-if="title" class="font-medium">{{ title }}</p>
            <p>{{ message }}</p>
        </div>
        <button
            v-if="retryable"
            type="button"
            class="shrink-0 rounded-md border border-red-300 px-2 py-1 hover:bg-red-100"
            @click="$emit('retry')"
        >
            Повторить
        </button>
    </div>
</template>
