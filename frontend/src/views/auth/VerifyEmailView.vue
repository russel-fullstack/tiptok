<script setup lang="ts">
import { ref } from 'vue'
import { resendVerification } from '../../services/auth'
import { errorMessage } from '../../services/api'

const sent = ref(false)
const error = ref('')

async function resend() {
  error.value = ''
  try {
    await resendVerification()
    sent.value = true
  } catch (e) {
    error.value = errorMessage(e)
  }
}
</script>

<template>
  <section class="mx-auto max-w-md space-y-4 rounded-xl bg-white p-6 shadow">
    <h1 class="text-2xl font-bold">Vérifie ton email</h1>
    <p>Clique sur le lien reçu par email pour activer ton compte.</p>
    <p v-if="sent" role="status" class="text-green-700">Nouveau lien envoyé.</p>
    <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
    <button type="button" class="rounded bg-blue-600 px-4 py-3 text-white" @click="resend">Renvoyer le lien</button>
  </section>
</template>
