<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import axios from 'axios'
import { useAuthStore } from '../../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = reactive({
  email: '',
  password: '',
  remember: false,
})

const errors = ref<Record<string, string>>({})
const message = ref('')

async function submitLogin() {
  errors.value = {}
  message.value = ''

  try {
    await auth.login({
      email: form.email,
      password: form.password,
    })

    await router.push('/')
  } catch (error: unknown) {
    if (axios.isAxiosError(error)) {
      const data = error.response?.data

      if (data?.errors) {
        errors.value = Object.fromEntries(
          Object.entries(data.errors).map(([key, value]) => [
            key,
            Array.isArray(value) ? String(value[0]) : String(value),
          ]),
        )
      } else {
        message.value =
          data?.message ?? 'Connexion impossible. Vérifie tes identifiants.'
      }
    } else {
      message.value = 'Une erreur inattendue est survenue.'
    }
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10">
    <section class="w-full max-w-md rounded-3xl border border-slate-200 bg-white p-8 shadow-sm">
      <RouterLink to="/" class="text-2xl font-black tracking-tight text-blue-600">
        TipTok<span class="text-slate-900">.</span>
      </RouterLink>

      <h1 class="mt-8 text-3xl font-bold text-slate-900">
        Bon retour !
      </h1>

      <p class="mt-2 text-sm text-slate-500">
        Connecte-toi pour retrouver la communauté TipTok.
      </p>

      <div
        v-if="message || auth.error"
        role="alert"
        class="mt-5 rounded-xl bg-red-50 p-3 text-sm text-red-700"
      >
        {{ message || auth.error }}
      </div>

      <form class="mt-7 space-y-5" @submit.prevent="submitLogin">
        <div>
          <label for="email" class="mb-2 block text-sm font-semibold text-slate-700">
            Adresse email
          </label>
          <input
            id="email"
            v-model.trim="form.email"
            type="email"
            autocomplete="email"
            required
            class="w-full rounded-xl border border-slate-200 px-4 py-3 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
            placeholder="toi@exemple.com"
          />
          <p v-if="errors.email" class="mt-1 text-sm text-red-600">
            {{ errors.email }}
          </p>
        </div>

        <div>
          <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">
            Mot de passe
          </label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            autocomplete="current-password"
            required
            class="w-full rounded-xl border border-slate-200 px-4 py-3 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
            placeholder="Ton mot de passe"
          />
          <p v-if="errors.password" class="mt-1 text-sm text-red-600">
            {{ errors.password }}
          </p>
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600">
          <input v-model="form.remember" type="checkbox" class="rounded border-slate-300" />
          Se souvenir de moi
        </label>

        <button
          type="submit"
          :disabled="auth.loading"
          class="w-full rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {{ auth.loading ? 'Connexion...' : 'Se connecter' }}
        </button>
      </form>

      <p class="mt-6 text-center text-sm text-slate-500">
        Pas encore de compte ?
        <RouterLink to="/register" class="font-semibold text-blue-600 hover:text-blue-700">
          Créer un compte
        </RouterLink>
      </p>
    </section>
  </main>
</template>