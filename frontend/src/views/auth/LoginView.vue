<script setup lang="ts">
import { ref } from "vue";
import { useRoute, useRouter, RouterLink } from "vue-router";
import { useAuthStore } from "../../stores/auth";

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();
const email = ref("");
const password = ref("");

async function submit() {
  try {
    await auth.login(email.value, password.value);
    const redirect =
      typeof route.query.redirect === "string" &&
      route.query.redirect.startsWith("/")
        ? route.query.redirect
        : "/";
    await router.push(redirect);
  } catch {
    /* l'erreur est exposée dans auth.error ; la saisie est conservée */
  }
}
</script>

<template>
  <form
    class="mx-auto max-w-md space-y-4 rounded-xl bg-white p-6 shadow"
    @submit.prevent="submit"
  >
    <h1 class="text-2xl font-bold">Connexion à TipTok</h1>

    <label class="block text-sm font-medium"
      >Email
      <input
        v-model.trim="email"
        type="email"
        
        autocomplete="email"
        class="mt-1 w-full rounded border p-3"
      />
    </label>
    <label class="block text-sm font-medium"
      >Mot de passe
      <input
        v-model="password"
        type="password"
        
        autocomplete="current-password"
        class="mt-1 w-full rounded border p-3"
      />
    </label>

    <p v-if="auth.error" role="alert" class="text-sm text-red-600">
      {{ auth.error }}
    </p>

    <button
      type="submit"
      :disabled="auth.loading"
      class="w-full rounded bg-blue-600 px-4 py-3 font-semibold text-white disabled:opacity-60"
    >
      {{ auth.loading ? "Connexion…" : "Se connecter" }}
    </button>

    <p class="text-center text-sm">
      <RouterLink to="/forgot-password" class="text-blue-600 underline"
        >Mot de passe oublié ?</RouterLink
      >
      ·
      <RouterLink to="/register" class="text-blue-600 underline"
        >Créer un compte</RouterLink
      >
    </p>
  </form>
</template>
