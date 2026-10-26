<!-- ErrorMessage.vue -->
<script setup lang="ts">
defineProps<{ message: string }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div role="alert" class="rounded border border-red-200 bg-red-50 p-4 text-red-700">
    <p>{{ message }}</p>
    <button type="button" class="mt-2 underline" @click="$emit('retry')">Réessayer</button>
  </div>
</template>
