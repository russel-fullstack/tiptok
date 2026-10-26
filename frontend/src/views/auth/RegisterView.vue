<script setup lang="ts">
import { reactive, ref } from "vue";
import { useRouter, RouterLink } from "vue-router";
import { useAuthStore } from "../../stores/auth";
import { fieldErrors } from "../../services/api";

const auth = useAuthStore();
const router = useRouter();
const form = reactive({
  name: "",
  username: "",
  email: "",
  password: "",
  password_confirmation: "",
});
const errors = ref<Record<string, string>>({});

async function submit() {
  errors.value = {};
  if (form.password !== form.password_confirmation) {
    errors.value = {
      password_confirmation: "Les mots de passe ne correspondent pas.",
    };
    return;
  }
  try {
    await auth.register({ ...form });
    await router.push("/");
  } catch (e) {
    errors.value = fieldErrors(e);
  }
}
</script>

<template>
  <form
    class="mx-auto max-w-md space-y-4 rounded-xl bg-white p-6 shadow"
    @submit.prevent="submit"
  >
    <h1 class="text-2xl font-bold">Créer un compte</h1>

    <label class="block text-sm font-medium"
      >Nom
      <input
        v-model.trim="form.name"
        
        maxlength="100"
        autocomplete="name"
        class="mt-1 w-full rounded border p-3"
      />
      <span v-if="errors.name" role="alert" class="text-red-600">{{
        errors.name
      }}</span>
    </label>
    <label class="block text-sm font-medium"
      >Nom d'utilisateur
      <input
        v-model.trim="form.username"
        
        minlength="3"
        maxlength="30"
        pattern="[A-Za-z0-9_\-]+"
        autocomplete="username"
        class="mt-1 w-full rounded border p-3"
      />
      <span v-if="errors.username" role="alert" class="text-red-600">{{
        errors.username
      }}</span>
    </label>
    <label class="block text-sm font-medium"
      >Email
      <input
        v-model.trim="form.email"
        type="email"
        
        autocomplete="email"
        class="mt-1 w-full rounded border p-3"
      />
      <span v-if="errors.email" role="alert" class="text-red-600">{{
        errors.email
      }}</span>
    </label>
    <label class="block text-sm font-medium"
      >Mot de passe (8 caractères min.)
      <input
        v-model="form.password"
        type="password"
        
        minlength="8"
        autocomplete="new-password"
        class="mt-1 w-full rounded border p-3"
      />
      <span v-if="errors.password" role="alert" class="text-red-600">{{
        errors.password
      }}</span>
    </label>
    <label class="block text-sm font-medium"
      >Confirmation
      <input
        v-model="form.password_confirmation"
        type="password"
        
        autocomplete="new-password"
        class="mt-1 w-full rounded border p-3"
      />
      <span
        v-if="errors.password_confirmation"
        role="alert"
        class="text-red-600"
        >{{ errors.password_confirmation[0] }}</span
      >
    </label>

    <p
      v-if="auth.error && !Object.keys(errors).length"
      role="alert"
      class="text-sm text-red-600"
    >
      {{ auth.error[0] }}
    </p>

    <button
      type="submit"
      :disabled="auth.loading"
      class="w-full rounded bg-blue-600 px-4 py-3 font-semibold text-white disabled:opacity-60"
    >
      {{ auth.loading ? "Création…" : "S'inscrire" }}
    </button>
    <p class="text-center text-sm">
      Déjà inscrit ?
      <RouterLink to="/login" class="text-blue-600 underline"
        >Se connecter</RouterLink
      >
    </p>
  </form>
</template>
