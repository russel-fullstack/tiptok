<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ name: string; src?: string | null; size?: number }>(), { src: null, size: 40 })
const initial = computed(() => props.name.trim().charAt(0).toUpperCase() || '?')
</script>

<template>
  <img v-if="src" :src="src" :alt="`Avatar de ${name}`" :width="size" :height="size"
       class="rounded-full object-cover" :style="{ width: size + 'px', height: size + 'px' }">
  <span v-else role="img" :aria-label="`Avatar de ${name}`"
        class="inline-flex items-center justify-center rounded-full bg-blue-600 font-semibold text-white"
        :style="{ width: size + 'px', height: size + 'px' }">{{ initial }}</span>
</template>
