<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
const auth = useAuthStore()
</script>

<template>
  <header class="border-b bg-white">
    <nav class="mx-auto flex max-w-2xl items-center gap-4 px-4 py-3" aria-label="Navigation principale">
      <RouterLink to="/" class="text-xl font-extrabold text-blue-600">TipTok</RouterLink>
      <RouterLink v-if="!auth.user" to="/login" class="ml-auto">Connexion</RouterLink>
    </nav>
  </header>
</template>