export interface UserBrief {
  id: number
  name: string
  username: string
  avatar_url: string | null
}

export interface AuthUser extends UserBrief {
  email: string
  bio: string | null
}

export interface PublicProfile extends UserBrief {
  bio: string | null
  followers_count: number
  following_count: number
  is_following: boolean
}

export interface Post {
  id: number
  content: string
  created_at: string
  user: UserBrief
  likes_count: number
  comments_count: number
  liked_by_me: boolean
}

export interface PostComment {
  id: number
  post_id: number
  user_id: number
  content: string
  created_at: string
  user: UserBrief
}

export interface Paginated<T> {
  success: boolean
  data: T[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface AppNotification {
  id: string
  type: string
  message: string
  actor_id: number | null
  post_id: number | null
  read_at: string | null
  created_at: string
}

export interface ApiErrorBody {
  message?: string
  errors?: Record<string, string[]>
}