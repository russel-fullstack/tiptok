<script setup lang="ts">
import { ref } from 'vue'
import { forgotPassword } from '../../services/auth'
import { errorMessage } from '../../services/api'

const email = ref('')
const loading = ref(false)
const error = ref('')
const sent = ref(false)

async function submit() {
  loading.value = true
  error.value = ''
  try {
    await forgotPassword(email.value)
    sent.value = true
  } catch (e) {
    error.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="mx-auto max-w-md space-y-4 rounded-xl bg-white p-6 shadow" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Mot de passe oublié</h1>
    <label class="block text-sm font-medium">Email
      <input v-model.trim="email" type="email" required class="mt-1 w-full rounded border p-3">
    </label>
    <p v-if="sent" role="status" class="text-green-700">Si ce compte existe, un lien vient d'être envoyé.</p>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <button type="submit" :disabled="loading || sent" class="w-full rounded bg-blue-600 px-4 py-3 text-white disabled:opacity-60">
      {{ loading ? 'Envoi…' : 'Envoyer le lien' }}
    </button>
  </form>
</template>
