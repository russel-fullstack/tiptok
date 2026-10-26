<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { resetPassword } from '../../services/auth'
import { errorMessage } from '../../services/api'

const route = useRoute()
const router = useRouter()
const form = reactive({ password: '', password_confirmation: '' })
const loading = ref(false)
const error = ref('')

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await resetPassword({
      token: String(route.params.token),
      email: String(route.query.email ?? ''),
      ...form,
    })
    await router.push({ name: 'login' })
  } catch (e) {
    error.value = errorMessage(e, 'Réinitialisation impossible (lien expiré ?).')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="mx-auto max-w-md space-y-4 rounded-xl bg-white p-6 shadow" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Nouveau mot de passe</h1>
    <label class="block text-sm font-medium">Mot de passe
      <input v-model="form.password" type="password" required minlength="8" autocomplete="new-password" class="mt-1 w-full rounded border p-3">
    </label>
    <label class="block text-sm font-medium">Confirmation
      <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="mt-1 w-full rounded border p-3">
    </label>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <button type="submit" :disabled="loading" class="w-full rounded bg-blue-600 px-4 py-3 text-white disabled:opacity-60">
      {{ loading ? 'Enregistrement…' : 'Réinitialiser' }}
    </button>
  </form>
</template>
