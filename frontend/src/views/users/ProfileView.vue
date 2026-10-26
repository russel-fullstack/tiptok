<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import { fetchProfile } from '../../services/users'
import { errorMessage } from '../../services/api'
import { useAuthStore } from '../../stores/auth'
import UserAvatar from '../../components/UserAvatar.vue'
import LoadingState from '../../components/LoadingState.vue'
import ErrorMessage from '../../components/ErrorMessage.vue'
import type { PublicProfile } from '../../types'

const route = useRoute()
const auth = useAuthStore()
const profile = ref<PublicProfile | null>(null)
const loading = ref(true)
const error = ref('')
const notFound = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  notFound.value = false
  try {
    profile.value = await fetchProfile(String(route.params.id))
  } catch (e) {
    notFound.value = axios.isAxiosError(e) && e.response?.status === 404
    error.value = notFound.value ? '' : errorMessage(e)
    profile.value = null
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => route.params.id, load)
</script>

<template>
  <LoadingState v-if="loading" />
  <p v-else-if="notFound" role="alert" class="text-center text-gray-600">Profil introuvable.</p>
  <ErrorMessage v-else-if="error" :message="error" @retry="load" />

  <section v-else-if="profile" class="space-y-6">
    <header class="flex items-start gap-4 rounded-xl bg-white p-6 shadow">
      <UserAvatar :name="profile.name" :src="profile.avatar_url" :size="72" />
      <div class="flex-1">
        <h1 class="text-xl font-bold">{{ profile.name }}</h1>
        <p class="text-gray-500">@{{ profile.username }}</p>
        <p v-if="profile.bio" class="mt-2">{{ profile.bio }}</p>
        <p class="mt-3 text-sm text-gray-600">
          <strong>{{ profile.followers_count }}</strong> abonnés ·
          <strong>{{ profile.following_count }}</strong> abonnements
        </p>
        <!-- Ch. 7 : bouton Suivre / Se désabonner (masqué sur son propre profil) -->
        <!-- Ch. 12 : lien « Modifier le profil » si auth.user?.id === profile.id -->
      </div>
    </header>

    <!-- Ch. 4 : liste paginée des publications du profil -->
  </section>
</template>
