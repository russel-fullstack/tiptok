import { api } from './api'
import type { Paginated, Post, PublicProfile } from '../types'

export async function fetchProfile(id: number | string): Promise<PublicProfile> {
  const { data } = await api.get<{ success: boolean; data: PublicProfile }>(`/api/v1/users/${id}`)
  return data.data
}

export async function fetchUserPosts(id: number | string, page = 1): Promise<Paginated<Post>> {
  const { data } = await api.get<Paginated<Post>>(`/api/v1/users/${id}/posts`, { params: { page } })
  return data
}