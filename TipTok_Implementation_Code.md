# TipTok — Implémentation concrète du plan de développement

## Laravel 13 API + Vue 3 TypeScript + Pinia + Tailwind CSS v4

> **But de ce document :** transformer le *Guide complet de développement* en code
> prêt à intégrer, **fonctionnalité par fonctionnalité**.
> Chaque chapitre suit le même cycle : **Base de données → API Laravel → Vue/TypeScript → Tests → Validation**.
> Ne passe au chapitre suivant qu'après avoir exécuté les tests du chapitre courant.

**Avertissement d'intégration.** Ton projet contient déjà des morceaux (posts, likes, commentaires,
follows, profils, début de Reverb). Pour chaque fichier ci-dessous : **compare avec l'existant**,
fusionne, ne remplace pas aveuglément. Les noms de colonnes et les types TypeScript doivent rester
alignés sur tes migrations réelles.

---

## Table des matières

0. [Architecture, conventions et ordre de travail](#0-architecture-conventions-et-ordre-de-travail)
1. [Socle commun et tests de santé](#1-socle-commun-et-tests-de-santé)
2. [Authentification et session](#2-authentification-et-session)
3. [Profils utilisateurs](#3-profils-utilisateurs)
4. [Publications (Posts)](#4-publications-posts)
5. [Likes / Unlike](#5-likes--unlike)
6. [Commentaires](#6-commentaires)
7. [Follow / Unfollow](#7-follow--unfollow)
8. [Fil global et fil des abonnements](#8-fil-global-et-fil-des-abonnements)
9. [Temps réel avec Laravel Reverb](#9-temps-réel-avec-laravel-reverb)
10. [Notifications persistantes](#10-notifications-persistantes)
11. [Recherche](#11-recherche-utilisateurs-et-publications)
12. [Modifier le profil et l'avatar](#12-modifier-le-profil-et-lavatar)
13. [Sécurité, modération et signalements](#13-sécurité-modération-et-signalements)
14. [Accessibilité, UX et responsive](#14-accessibilité-ux-et-responsive)
15. [Tests automatisés Laravel](#15-tests-automatisés-laravel)
16. [Tests frontend (Vitest)](#16-tests-frontend-vitest)
17. [CI, qualité et déploiement](#17-ci-qualité-et-déploiement)
18. [Checklist finale](#18-checklist-finale)
19. [Méthode de travail et ordre conseillé](#19-méthode-de-travail-et-ordre-conseillé)

---

# 0. Architecture, conventions et ordre de travail

## 0.1 Arborescence cible

```text
app-tiptok/
├── api-tiptok/
│   ├── app/
│   │   ├── Actions/Fortify/CreateNewUser.php
│   │   ├── Events/{PostLikedEvent,NewCommentEvent,UserFollowedEvent}.php
│   │   ├── Http/
│   │   │   ├── Controllers/Api/V1/
│   │   │   │   ├── PostController.php
│   │   │   │   ├── LikeController.php
│   │   │   │   ├── CommentController.php
│   │   │   │   ├── FollowController.php
│   │   │   │   ├── UserProfileController.php
│   │   │   │   ├── ProfileController.php
│   │   │   │   ├── NotificationController.php
│   │   │   │   ├── SearchController.php
│   │   │   │   ├── ReportController.php
│   │   │   │   └── Admin/ReportModerationController.php
│   │   │   └── Resources/{UserResource,UserBriefResource,PostResource,CommentResource,ReportResource}.php
│   │   ├── Models/{User,Post,Like,Comment,Report}.php
│   │   ├── Notifications/TipTokNotification.php
│   │   └── Providers/AppServiceProvider.php   # Gate + rate limiters
│   ├── database/{migrations,factories}/
│   ├── routes/{api.php,channels.php}
│   └── tests/Feature/
└── frontend/
    └── src/
        ├── components/{AppHeader,AppNav,PostCard,PostComposer,CommentSection,
        │               LoadingState,ErrorMessage,ConfirmDialog,UserAvatar,ReportButton}.vue
        ├── composables/{useDebounce,useRealtimePost}.ts
        ├── router/index.ts
        ├── services/{api,health,auth,users,posts,comments,follows,
        │             notifications,search,reports,echo}.ts
        ├── stores/{auth,notifications}.ts
        ├── types/index.ts
        └── views/
            ├── FeedView.vue  SearchView.vue  NotFoundView.vue
            ├── auth/{Login,Register,ForgotPassword,ResetPassword,VerifyEmail}View.vue
            ├── users/{ProfileView,EditProfileView}.vue
            └── notifications/NotificationsView.vue
```

## 0.2 Commandes de démarrage

```bash
# Terminal 1 — API
cd ~/sites/app-tiptok/api-tiptok && php artisan serve

# Terminal 2 — WebSocket Reverb
cd ~/sites/app-tiptok/api-tiptok && php artisan reverb:start --debug

# Terminal 3 — Worker de queue (broadcasts et notifications)
cd ~/sites/app-tiptok/api-tiptok && php artisan queue:work

# Terminal 4 — Frontend
cd ~/sites/app-tiptok/frontend && npm run dev
```

## 0.3 Règles non négociables

| Règle | Application |
|---|---|
| Ne pas recréer les routes Fortify | Vérifier `php artisan route:list` avant de toucher à l'auth |
| Pas d'imports `Route` ou de groupes `auth:sanctum` dupliqués | Un seul groupe protégé par version d'API |
| Frontend séparé du backend | Deux projets, communication HTTP + WebSocket uniquement |
| Pas d'URL en dur | `import.meta.env.VITE_*` côté Vue, `config()`/`env()` côté Laravel |
| Aucun secret côté frontend | `APP_KEY`, `REVERB_APP_SECRET`, mots de passe, tokens : jamais dans `VITE_*` |
| Double validation | Vue valide pour l'UX, **Laravel valide pour la sécurité** |
| Autorisation sur chaque modification | `abort_unless(...)` ou Policy |
| Transactions | Dès qu'une action modifie plusieurs tables liées |
| Sanctum SPA (cookies) | `statefulApi()`, CORS avec credentials, flux CSRF |
| Cohérence types ↔ migrations | Les interfaces TS reflètent les colonnes réelles |

## 0.4 Format standard des réponses API

```jsonc
// Succès simple
{ "success": true, "message": "…", "data": { } }

// Liste paginée (API Resources)
{ "success": true, "data": [ ], "links": { }, "meta": { "current_page": 1, "last_page": 3, "per_page": 10, "total": 27 } }

// Erreur de validation (422, format natif Laravel)
{ "message": "…", "errors": { "champ": ["…"] } }
```

---

# 1. Socle commun et tests de santé

## 1.1 Backend

### `routes/api.php` (squelette final, complété chapitre par chapitre)

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Utilisateur courant (SPA Sanctum)
Route::middleware('auth:sanctum')->get('/user', fn (Request $request) =>
    new \App\Http\Resources\UserResource($request->user())
);

Route::prefix('v1')->group(function () {

    Route::get('/health', function () {
        return response()->json([
            'success'     => true,
            'application' => 'TipTok API',
            'version'     => '1.0.0',
            'message'     => 'API opérationnelle',
        ]);
    });

    // ── Routes publiques (chapitres 3, 4, 6, 8, 11) ──────────────────
    // Les routes suivantes seront ajoutées ici.

    // ── Routes protégées (un SEUL groupe) ────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {
        // Les routes protégées seront ajoutées ici.
    });
});
```

### `bootstrap/app.php` — Sanctum stateful + Broadcasting

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Auth des canaux privés via Sanctum : POST /api/broadcasting/auth
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Réponses JSON homogènes pour l'API
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) =>
            $request->is('api/*') || $request->expectsJson()
        );
    })
    ->create();
```

### `.env` (backend)

```dotenv
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173

SESSION_DRIVER=database
SESSION_DOMAIN=localhost
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000

QUEUE_CONNECTION=database
BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=public
```

### `config/cors.php`

```php
<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'login', 'register', 'logout',
                'forgot-password', 'reset-password', 'email/verification-notification',
                'broadcasting/auth'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,   // indispensable avec les cookies Sanctum
];
```

## 1.2 Frontend

### `.env`

```dotenv
VITE_API_BASE_URL=http://localhost:8000
```

### `src/types/index.ts` — types partagés

```ts
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
```

### `src/services/api.ts`

```ts
import axios, { AxiosError } from 'axios'
import type { ApiErrorBody } from '../types'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  timeout: 10_000,
})

/** Transforme n'importe quelle erreur en message lisible (401/403/404/422/429/réseau). */
export function errorMessage(e: unknown, fallback = 'Une erreur est survenue.'): string {
  if (!axios.isAxiosError(e)) return fallback
  const err = e as AxiosError<ApiErrorBody>
  if (!err.response) return 'Réseau indisponible. Vérifie ta connexion.'
  const { status, data } = err.response
  switch (status) {
    case 401: return 'Connecte-toi pour continuer.'
    case 403: return "Tu n'as pas le droit d'effectuer cette action."
    case 404: return 'Ressource introuvable.'
    case 419: return 'Session expirée. Recharge la page.'
    case 422: return data?.errors ? Object.values(data.errors)[0]?.[0] ?? fallback : data?.message ?? fallback
    case 429: return 'Trop de requêtes. Réessaie dans un instant.'
    default:  return data?.message ?? fallback
  }
}

export function fieldErrors(e: unknown): Record<string, string> {
  if (!axios.isAxiosError(e) || e.response?.status !== 422) return {}
  const errs = (e.response.data as ApiErrorBody).errors ?? {}
  return Object.fromEntries(Object.entries(errs).map(([k, v]) => [k, v[0]]))
}
```

### `src/services/health.ts`

```ts
import { api } from './api'

export interface HealthResponse {
  success: boolean
  application: string
  version: string
  message: string
}

export async function checkApiHealth(): Promise<HealthResponse> {
  const { data } = await api.get<HealthResponse>('/api/v1/health')
  return data
}
```

### `src/router/index.ts` — routeur complet (les vues sont créées chapitre par chapitre)

```ts
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'feed', component: () => import('../views/FeedView.vue') },                       // ch. 4, 8
    { path: '/login', name: 'login', component: () => import('../views/auth/LoginView.vue'), meta: { guestOnly: true } },
    { path: '/register', name: 'register', component: () => import('../views/auth/RegisterView.vue'), meta: { guestOnly: true } },
    { path: '/forgot-password', name: 'forgot-password', component: () => import('../views/auth/ForgotPasswordView.vue'), meta: { guestOnly: true } },
    { path: '/reset-password/:token', name: 'reset-password', component: () => import('../views/auth/ResetPasswordView.vue'), meta: { guestOnly: true } },
    { path: '/verify-email', name: 'verify-email', component: () => import('../views/auth/VerifyEmailView.vue'), meta: { requiresAuth: true } },
    { path: '/users/:id(\\d+)', name: 'profile', component: () => import('../views/users/ProfileView.vue') },           // ch. 3
    { path: '/settings/profile', name: 'edit-profile', component: () => import('../views/users/EditProfileView.vue'), meta: { requiresAuth: true } }, // ch. 12
    { path: '/notifications', name: 'notifications', component: () => import('../views/notifications/NotificationsView.vue'), meta: { requiresAuth: true } }, // ch. 10
    { path: '/search', name: 'search', component: () => import('../views/SearchView.vue') },             // ch. 11
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../views/NotFoundView.vue') },
  ],
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.initialized) await auth.fetchUser()

  if (to.meta.requiresAuth && !auth.user) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.guestOnly && auth.user) return { name: 'feed' }
})

export default router
```

### `src/main.ts`

```ts
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './style.css'   // contient : @import "tailwindcss";

createApp(App).use(createPinia()).use(router).mount('#app')
```

### `src/App.vue`

```vue
<script setup lang="ts">
import AppHeader from './components/AppHeader.vue'
</script>

<template>
  <div class="min-h-screen bg-gray-50 text-gray-900">
    <AppHeader />
    <main class="mx-auto max-w-2xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
```

### Fichiers minimaux requis par le routeur et `App.vue`

Crée-les dès maintenant pour que `npm run build` passe ; ils sont complétés plus loin :

- `src/components/AppHeader.vue` : version minimale ci-dessous, **remplacée** par la version finale du §10.3.
- `src/views/NotFoundView.vue` : voir §14.2.
- `src/style.css` : voir §14.2.
- Les vues des chapitres suivants (`FeedView`, `ProfileView`, `SearchView`, …) peuvent d'abord être de simples
  `<template><p>À venir</p></template>` ; remplace-les au fil des chapitres.

```vue
<!-- src/components/AppHeader.vue — version minimale du chapitre 1 -->
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
```

> Le store `auth` (§2.3) est requis par le routeur : réalise le chapitre 1 et le chapitre 2 d'une traite.

## 1.3 Tests du chapitre 1

```bash
php artisan route:list --path=api/v1
php artisan test
curl http://localhost:8000/api/v1/health
npm run build
```

Test Laravel — `tests/Feature/HealthTest.php` :

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_is_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('application', 'TipTok API');
    }
}
```

**Validation :** health OK, aucune erreur CORS dans la console, `npm run build` passe.

---

# 2. Authentification et session

**Objectif :** inscription, connexion, session persistante, déconnexion, mot de passe oublié, routes protégées.

## 2.1 Base de données — champs de profil sur `users`

```bash
php artisan make:migration add_profile_fields_to_users_table --table=users
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // À n'exécuter que pour les colonnes absentes de ta base actuelle
            $table->string('username', 30)->unique()->after('name');
            $table->string('avatar_url')->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('avatar_url'); // chemin disque (ch. 12)
            $table->string('bio', 300)->nullable()->after('avatar_path');
            $table->string('role', 20)->default('user')->after('bio');       // user | moderator | admin (ch. 13)
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'avatar_url', 'avatar_path', 'bio', 'role']);
        });
    }
};
```

> Si `username`, `avatar_url` ou `bio` existent déjà, retire-les de cette migration.

## 2.2 API — Fortify + Sanctum

### Vérifications avant toute modification

```bash
php artisan route:list --except-vendor=false | grep -E "login|register|logout|password|verify"
```

### `config/fortify.php` (extraits)

```php
use Laravel\Fortify\Features;

'guard' => 'web',
'views' => false,            // API : pas de vues Blade
'home'  => '/',
'features' => [
    Features::registration(),
    Features::resetPasswords(),
    Features::emailVerification(),   // retire cette ligne si la vérification n'est pas activée
],
```

### `app/Actions/Fortify/CreateNewUser.php`

```php
<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    public function create(array $input): User
    {
        Validator::make($input, [
            'name'     => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:30', 'unique:users,username'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ])->validate();

        return User::create([
            'name'     => $input['name'],
            'username' => strtolower($input['username']),
            'email'    => strtolower($input['email']),
            'password' => $input['password'],   // haché par le cast 'hashed' du modèle User (§3.1)
        ]);
    }
}
```

> Le mot de passe est haché par le cast `'password' => 'hashed'` du modèle `User` (défaut des Laravel récents).
> Si ton `User` n'a pas ce cast, ajoute-le (§3.1) ou utilise `Hash::make($input['password'])` ici.

### Lien de réinitialisation vers le frontend — `app/Providers/AppServiceProvider.php`

```php
use Illuminate\Auth\Notifications\ResetPassword;

public function boot(): void
{
    ResetPassword::createUrlUsing(function ($user, string $token) {
        return rtrim(config('app.frontend_url'), '/')
            . "/reset-password/{$token}?email=" . urlencode($user->getEmailForPasswordReset());
    });
}
```

Dans `config/app.php` : `'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),`

### API Resources

```bash
php artisan make:resource UserBriefResource
php artisan make:resource UserResource
```

`app/Http/Resources/UserBriefResource.php` — **uniquement** les champs publics :

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserBriefResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'username'   => $this->username,
            'avatar_url' => $this->avatar_url,
        ];
    }
}
```

`app/Http/Resources/UserResource.php` — utilisateur **connecté** (inclut email) :

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public static $wrap = null;   // /api/user renvoie l'objet directement

    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'username'   => $this->username,
            'email'      => $this->email,
            'avatar_url' => $this->avatar_url,
            'bio'        => $this->bio,
        ];
    }
}
```

## 2.3 Frontend

### `src/services/auth.ts`

```ts
import { api } from './api'
import type { AuthUser } from '../types'

export interface RegisterPayload {
  name: string
  username: string
  email: string
  password: string
  password_confirmation: string
}

export async function getCurrentUser(): Promise<AuthUser> {
  const { data } = await api.get<AuthUser>('/api/user')
  return data
}

export async function login(payload: { email: string; password: string }): Promise<AuthUser> {
  await api.get('/sanctum/csrf-cookie')
  await api.post('/login', payload)
  return getCurrentUser()
}

export async function register(payload: RegisterPayload): Promise<AuthUser> {
  await api.get('/sanctum/csrf-cookie')
  await api.post('/register', payload)
  return getCurrentUser()
}

export async function logout(): Promise<void> {
  await api.post('/logout')
}

export async function forgotPassword(email: string): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
  await api.post('/forgot-password', { email })
}

export async function resetPassword(payload: {
  token: string; email: string; password: string; password_confirmation: string
}): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
  await api.post('/reset-password', payload)
}

export async function resendVerification(): Promise<void> {
  await api.post('/email/verification-notification')
}
```

### `src/stores/auth.ts`

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as authService from '../services/auth'
import { errorMessage } from '../services/api'
import type { RegisterPayload } from '../services/auth'
import type { AuthUser } from '../types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  const initialized = ref(false)
  const loading = ref(false)
  const error = ref('')

  async function fetchUser() {
    try {
      user.value = await authService.getCurrentUser()
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function login(email: string, password: string) {
    loading.value = true
    error.value = ''
    try {
      user.value = await authService.login({ email, password })
    } catch (e) {
      error.value = errorMessage(e, 'Connexion impossible.')
      throw e
    } finally {
      loading.value = false
    }
  }

  async function register(payload: RegisterPayload) {
    loading.value = true
    error.value = ''
    try {
      user.value = await authService.register(payload)
    } catch (e) {
      error.value = errorMessage(e, 'Inscription impossible.')
      throw e
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await authService.logout()
    } finally {
      user.value = null
    }
  }

  function setUser(next: AuthUser) {   // utilisé après modification du profil (ch. 12)
    user.value = next
  }

  return { user, initialized, loading, error, fetchUser, login, register, logout, setUser }
})
```

### `src/views/auth/LoginView.vue`

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const email = ref('')
const password = ref('')

async function submit() {
  try {
    await auth.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/')
      ? route.query.redirect : '/'
    await router.push(redirect)
  } catch {
    /* l'erreur est exposée dans auth.error ; la saisie est conservée */
  }
}
</script>

<template>
  <form class="mx-auto max-w-md space-y-4 rounded-xl bg-white p-6 shadow" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Connexion à TipTok</h1>

    <label class="block text-sm font-medium">Email
      <input v-model.trim="email" type="email" required autocomplete="email"
             class="mt-1 w-full rounded border p-3">
    </label>
    <label class="block text-sm font-medium">Mot de passe
      <input v-model="password" type="password" required autocomplete="current-password"
             class="mt-1 w-full rounded border p-3">
    </label>

    <p v-if="auth.error" role="alert" class="text-sm text-red-600">{{ auth.error }}</p>

    <button type="submit" :disabled="auth.loading"
            class="w-full rounded bg-blue-600 px-4 py-3 font-semibold text-white disabled:opacity-60">
      {{ auth.loading ? 'Connexion…' : 'Se connecter' }}
    </button>

    <p class="text-center text-sm">
      <RouterLink to="/forgot-password" class="text-blue-600 underline">Mot de passe oublié ?</RouterLink>
      ·
      <RouterLink to="/register" class="text-blue-600 underline">Créer un compte</RouterLink>
    </p>
  </form>
</template>
```

### `src/views/auth/RegisterView.vue`

```vue
<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../../stores/auth'
import { fieldErrors } from '../../services/api'

const auth = useAuthStore()
const router = useRouter()
const form = reactive({ name: '', username: '', email: '', password: '', password_confirmation: '' })
const errors = ref<Record<string, string>>({})

async function submit() {
  errors.value = {}
  if (form.password !== form.password_confirmation) {
    errors.value = { password_confirmation: 'Les mots de passe ne correspondent pas.' }
    return
  }
  try {
    await auth.register({ ...form })
    await router.push('/')
  } catch (e) {
    errors.value = fieldErrors(e)
  }
}
</script>

<template>
  <form class="mx-auto max-w-md space-y-4 rounded-xl bg-white p-6 shadow" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Créer un compte</h1>

    <label class="block text-sm font-medium">Nom
      <input v-model.trim="form.name" required maxlength="100" autocomplete="name" class="mt-1 w-full rounded border p-3">
      <span v-if="errors.name" role="alert" class="text-red-600">{{ errors.name }}</span>
    </label>
    <label class="block text-sm font-medium">Nom d'utilisateur
      <input v-model.trim="form.username" required minlength="3" maxlength="30" pattern="[A-Za-z0-9_\-]+"
             autocomplete="username" class="mt-1 w-full rounded border p-3">
      <span v-if="errors.username" role="alert" class="text-red-600">{{ errors.username }}</span>
    </label>
    <label class="block text-sm font-medium">Email
      <input v-model.trim="form.email" type="email" required autocomplete="email" class="mt-1 w-full rounded border p-3">
      <span v-if="errors.email" role="alert" class="text-red-600">{{ errors.email }}</span>
    </label>
    <label class="block text-sm font-medium">Mot de passe (8 caractères min.)
      <input v-model="form.password" type="password" required minlength="8" autocomplete="new-password"
             class="mt-1 w-full rounded border p-3">
      <span v-if="errors.password" role="alert" class="text-red-600">{{ errors.password }}</span>
    </label>
    <label class="block text-sm font-medium">Confirmation
      <input v-model="form.password_confirmation" type="password" required autocomplete="new-password"
             class="mt-1 w-full rounded border p-3">
      <span v-if="errors.password_confirmation" role="alert" class="text-red-600">{{ errors.password_confirmation }}</span>
    </label>

    <p v-if="auth.error && !Object.keys(errors).length" role="alert" class="text-sm text-red-600">{{ auth.error }}</p>

    <button type="submit" :disabled="auth.loading"
            class="w-full rounded bg-blue-600 px-4 py-3 font-semibold text-white disabled:opacity-60">
      {{ auth.loading ? 'Création…' : "S'inscrire" }}
    </button>
    <p class="text-center text-sm">Déjà inscrit ? <RouterLink to="/login" class="text-blue-600 underline">Se connecter</RouterLink></p>
  </form>
</template>
```

### `src/views/auth/ForgotPasswordView.vue`

```vue
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
```

### `src/views/auth/ResetPasswordView.vue`

```vue
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
```

### `src/views/auth/VerifyEmailView.vue` (seulement si la vérification est activée)

```vue
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
```

## 2.4 Tests

### `tests/Feature/AuthTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $override = []): array
    {
        return array_merge([
            'name' => 'Alice', 'username' => 'alice', 'email' => 'alice@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ], $override);
    }

    public function test_user_can_register(): void
    {
        $this->postJson('/register', $this->payload())->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'alice@example.com', 'username' => 'alice']);
    }

    public function test_duplicate_email_or_username_is_rejected(): void
    {
        User::factory()->create(['email' => 'alice@example.com', 'username' => 'alice']);

        $this->postJson('/register', $this->payload())->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'username']);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'mauvais'])->assertUnprocessable();
    }

    public function test_authenticated_user_is_returned_by_api_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/user')
            ->assertOk()->assertJsonPath('id', $user->id)->assertJsonMissingPath('password');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/logout')->assertSuccessful();
        $this->assertGuest('web');
    }

    public function test_protected_route_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/api/v1/posts', ['content' => 'x'])->assertUnauthorized();
    }
}
```

### Frontend — `src/views/auth/__tests__/LoginView.spec.ts`

```ts
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import LoginView from '../LoginView.vue'
import { useAuthStore } from '../../../stores/auth'

const push = vi.fn()
vi.mock('vue-router', () => ({
  useRouter: () => ({ push }),
  useRoute: () => ({ query: {} }),
  RouterLink: { template: '<a><slot /></a>' },
}))

describe('LoginView', () => {
  beforeEach(() => { setActivePinia(createPinia()); push.mockClear() })

  it('affiche les champs de connexion', () => {
    const wrapper = mount(LoginView)
    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(true)
  })

  it('affiche l\'erreur du store sans perdre la saisie', async () => {
    const auth = useAuthStore()
    auth.login = vi.fn().mockRejectedValue(new Error('x'))
    auth.error = 'Identifiants invalides.'
    const wrapper = mount(LoginView)
    await wrapper.find('input[type="email"]').setValue('a@b.c')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('Identifiants invalides.')
    expect((wrapper.find('input[type="email"]').element as HTMLInputElement).value).toBe('a@b.c')
  })

  it('redirige après une connexion réussie', async () => {
    const auth = useAuthStore()
    auth.login = vi.fn().mockResolvedValue(undefined)
    const wrapper = mount(LoginView)
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(push).toHaveBeenCalledWith('/')
  })
})
```

**Validation :** inscription → connexion → `F5` conserve la session → déconnexion → `/login` ; route privée sans session redirige vers `/login`.

---

# 3. Profils utilisateurs

## 3.1 Base de données et modèle

Les colonnes `username`, `avatar_url`, `bio` sont créées au chapitre 2. La table `follows` est créée au chapitre 7 ; **les relations ci-dessous en dépendent** : exécute la migration `follows` (§7.1) avant de tester les compteurs.

### `app/Models/User.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;   // + TwoFactorAuthenticatable si activé

    protected $fillable = ['name', 'username', 'email', 'password', 'avatar_url', 'avatar_path', 'bio'];

    // Jamais renvoyés en JSON, même par erreur
    protected $hidden = ['password', 'remember_token', 'avatar_path', 'role', 'email_verified_at'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'following_id')->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id')->withTimestamps();
    }

    public function isModerator(): bool
    {
        return in_array($this->role, ['moderator', 'admin'], true);
    }
}
```


### `database/factories/UserFactory.php` (ajouts)

```php
public function definition(): array
{
    return [
        'name'              => fake()->name(),
        'username'          => fake()->unique()->userName(),
        'email'             => fake()->unique()->safeEmail(),
        'email_verified_at' => now(),
        'password'          => 'password',   // haché par le cast
        'bio'               => fake()->optional()->sentence(),
        'remember_token'    => \Illuminate\Support\Str::random(10),
    ];
}
```

## 3.2 API

```bash
php artisan make:controller Api/V1/UserProfileController
```

`app/Http/Controllers/Api/V1/UserProfileController.php` :

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function show(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user('sanctum');   // route publique : session facultative

        return response()->json([
            'success' => true,
            'data' => [
                'id'              => $user->id,
                'name'            => $user->name,
                'username'        => $user->username,
                'bio'             => $user->bio,
                'avatar_url'      => $user->avatar_url,
                'followers_count' => $user->followers()->count(),
                'following_count' => $user->following()->count(),
                'is_following'    => $viewer
                    ? $viewer->following()->where('users.id', $user->id)->exists()
                    : false,
            ],
        ]);
    }
}
```

> **Décision de conception :** le guide imbriquait `posts` (un paginator) dans le profil. Pour garder des
> types TS simples, les publications du profil sont servies par une route dédiée paginée
> (`GET /users/{user}/posts`, chapitre 4). Le profil ne contient donc **aucune** donnée privée ni liste volumineuse.

### Route (groupe public de `routes/api.php`)

```php
use App\Http\Controllers\Api\V1\UserProfileController;

Route::get('/users/{user}', [UserProfileController::class, 'show'])->whereNumber('user');
```

## 3.3 Frontend

### `src/services/users.ts`

```ts
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
```

### `src/components/UserAvatar.vue`

```vue
<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{ name: string; src?: string | null; size?: number }>(), { src: null, size: 40 })
const initial = computed(() => props.name.trim().charAt(0).toUpperCase() || '?')
</script>

<template>
  <img v-if="src" :src="src" :alt="`Avatar de ${name}`" :width="size" :height="size"
       class="rounded-full object-cover" :style="{ width: size + 'px', height: size + 'px' }">
  <span v-else role="img" :aria-label="`Avatar de ${name}`"
        class="inline-flex items-center justify-center rounded-full bg-blue-600 font-semibold text-white"
        :style="{ width: size + 'px', height: size + 'px' }">{{ initial }}</span>
</template>
```

### `src/views/users/ProfileView.vue` (version du chapitre 3 — Follow ajouté au ch. 7, posts au ch. 4)

```vue
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
```

### `src/components/LoadingState.vue` et `src/components/ErrorMessage.vue`

```vue
<!-- LoadingState.vue -->
<template>
  <p role="status" aria-live="polite" class="py-8 text-center text-gray-500">Chargement…</p>
</template>
```

```vue
<!-- ErrorMessage.vue -->
<script setup lang="ts">
defineProps<{ message: string }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div role="alert" class="rounded border border-red-200 bg-red-50 p-4 text-red-700">
    <p>{{ message }}</p>
    <button type="button" class="mt-2 underline" @click="$emit('retry')">Réessayer</button>
  </div>
</template>
```

## 3.4 Tests

### `tests/Feature/ProfileShowTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_profile_is_visible_without_login(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/v1/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.is_following', false);
    }

    public function test_unknown_profile_returns_404(): void
    {
        $this->getJson('/api/v1/users/999999')->assertNotFound();
    }

    public function test_private_data_is_never_exposed(): void
    {
        $user = User::factory()->create();
        $json = $this->getJson("/api/v1/users/{$user->id}")->assertOk()->json('data');

        foreach (['email', 'password', 'remember_token', 'role', 'avatar_path'] as $key) {
            $this->assertArrayNotHasKey($key, $json);
        }
    }

    public function test_is_following_matches_the_authenticated_viewer(): void
    {
        [$viewer, $target] = User::factory()->count(2)->create();
        $viewer->following()->attach($target->id);

        $this->actingAs($viewer)->getJson("/api/v1/users/{$target->id}")
            ->assertJsonPath('data.is_following', true)
            ->assertJsonPath('data.followers_count', 1);
    }
}
```

**Validation :** `/users/1` affiche le profil ; `/users/99999` affiche « Profil introuvable ».

---

# 4. Publications (Posts)

## 4.1 Base de données et modèle

```bash
php artisan make:model Post -mfc --api   # puis déplacer/adapter le contrôleur vers Api/V1
php artisan make:model Like -mf
php artisan make:policy PostPolicy --model=Post
```

Migration `create_posts_table` :

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->text('content');
    $table->timestamps();
    $table->index('created_at');
    $table->index(['user_id', 'created_at']);   // profil + fil des abonnements
});
```

### `app/Models/Post.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'content'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function likedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'likes')->withTimestamps();
    }
}
```

### `app/Models/Like.php` (nécessaire à `Post::likes()`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Like extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'post_id'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function post(): BelongsTo { return $this->belongsTo(Post::class); }
}
```

### `database/factories/PostFactory.php`

```php
public function definition(): array
{
    return [
        'user_id' => \App\Models\User::factory(),
        'content' => fake()->realText(120),
    ];
}
```

### `app/Policies/PostPolicy.php`

```php
<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function delete(User $user, Post $post): bool
    {
        return $user->id === $post->user_id;   // l'auteur uniquement
    }
}
```

## 4.2 API

### `app/Http/Resources/PostResource.php`

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'content'        => $this->content,
            'created_at'     => $this->created_at?->toISOString(),
            'user'           => new UserBriefResource($this->whenLoaded('user')),
            'likes_count'    => (int) ($this->likes_count ?? 0),
            'comments_count' => (int) ($this->comments_count ?? 0),
            'liked_by_me'    => (bool) ($this->liked_by_me ?? false),
        ];
    }
}
```

### `app/Http/Controllers/Api/V1/PostController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostController extends Controller
{
    /** GET /posts?feed=global|following  — fil public paginé */
    public function index(Request $request)
    {
        $request->validate(['feed' => ['nullable', 'in:global,following']]);

        $viewer = $request->user('sanctum');
        $feed = $request->query('feed', 'global');

        $query = $this->baseQuery($viewer);

        if ($feed === 'following') {
            abort_unless($viewer, 401, 'Connexion requise.');
            $query->whereIn('user_id', $viewer->following()->select('users.id'));
        }

        return PostResource::collection($query->paginate(10)->withQueryString())
            ->additional(['success' => true]);
    }

    /** GET /users/{user}/posts — publications d'un profil */
    public function byUser(Request $request, User $user)
    {
        $query = $this->baseQuery($request->user('sanctum'))->where('user_id', $user->id);

        return PostResource::collection($query->paginate(10))->additional(['success' => true]);
    }

    /** GET /posts/{post} */
    public function show(Request $request, Post $post)
    {
        $viewer = $request->user('sanctum');
        $post->load('user:id,name,username,avatar_url')->loadCount(['likes', 'comments']);

        if ($viewer) {
            $post->loadExists(['likes as liked_by_me' => fn ($q) => $q->where('user_id', $viewer->id)]);
        }

        return (new PostResource($post))->additional(['success' => true]);
    }

    /** POST /posts (auth) */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:500'],
        ]);

        $post = $request->user()->posts()->create(['content' => trim($validated['content'])]);
        $post->load('user:id,name,username,avatar_url')->loadCount(['likes', 'comments']);

        return (new PostResource($post))
            ->additional(['success' => true, 'message' => 'Publication créée.'])
            ->response()->setStatusCode(201);
    }

    /** DELETE /posts/{post} (auteur uniquement) */
    public function destroy(Post $post): JsonResponse
    {
        Gate::authorize('delete', $post);   // 403 sinon
        $post->delete();

        return response()->json(['success' => true, 'message' => 'Publication supprimée.']);
    }

    private function baseQuery(?User $viewer)
    {
        $query = Post::query()
            ->with('user:id,name,username,avatar_url')
            ->withCount(['likes', 'comments'])
            ->latest('id');

        if ($viewer) {
            $query->withExists(['likes as liked_by_me' => fn ($q) => $q->where('user_id', $viewer->id)]);
        }

        return $query;
    }
}
```

### Routes

```php
use App\Http\Controllers\Api\V1\PostController;

// Groupe public
Route::get('/posts', [PostController::class, 'index']);
Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post');
Route::get('/users/{user}/posts', [PostController::class, 'byUser'])->whereNumber('user');

// Groupe auth:sanctum
Route::post('/posts', [PostController::class, 'store']);
Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');
```

> Les routes publiques lisent la session via `$request->user('sanctum')` : un visiteur anonyme obtient
> `liked_by_me = false`, un utilisateur connecté obtient l'état réel.

## 4.3 Frontend

### `src/services/posts.ts`

```ts
import { api } from './api'
import type { Paginated, Post } from '../types'

export type FeedType = 'global' | 'following'

export async function fetchPosts(page = 1, feed: FeedType = 'global'): Promise<Paginated<Post>> {
  const { data } = await api.get<Paginated<Post>>('/api/v1/posts', { params: { page, feed } })
  return data
}

export async function fetchPost(id: number): Promise<Post> {
  const { data } = await api.get<{ data: Post }>(`/api/v1/posts/${id}`)
  return data.data
}

export async function createPost(content: string): Promise<Post> {
  const { data } = await api.post<{ data: Post }>('/api/v1/posts', { content })
  return data.data
}

export async function deletePost(id: number): Promise<void> {
  await api.delete(`/api/v1/posts/${id}`)
}

// Ch. 5
export interface LikeResponse { success: boolean; message: string; likes_count: number; liked: boolean }

export async function likePost(id: number): Promise<LikeResponse> {
  const { data } = await api.post<LikeResponse>(`/api/v1/posts/${id}/like`)
  return data
}

export async function unlikePost(id: number): Promise<LikeResponse> {
  const { data } = await api.delete<LikeResponse>(`/api/v1/posts/${id}/like`)
  return data
}
```

### `src/utils/format.ts`

```ts
export function formatDate(iso: string): string {
  return new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
}
```

### `src/components/PostComposer.vue` (formulaire + compteur 500)

```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import { createPost } from '../services/posts'
import { errorMessage } from '../services/api'
import type { Post } from '../types'

const MAX = 500
const emit = defineEmits<{ created: [post: Post] }>()

const content = ref('')
const loading = ref(false)
const error = ref('')
const remaining = computed(() => MAX - content.value.length)
const canSubmit = computed(() => content.value.trim().length > 0 && remaining.value >= 0 && !loading.value)

async function submit() {
  if (!canSubmit.value) return
  loading.value = true
  error.value = ''
  try {
    const post = await createPost(content.value)
    content.value = ''
    emit('created', post)
  } catch (e) {
    error.value = errorMessage(e, 'Publication impossible.')   // la saisie est conservée
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="space-y-2 rounded-xl bg-white p-4 shadow" @submit.prevent="submit">
    <label for="composer" class="sr-only">Nouvelle publication</label>
    <textarea id="composer" v-model="content" rows="3" :maxlength="MAX + 50" placeholder="Quoi de neuf ?"
              class="w-full resize-none rounded border p-3" />
    <div class="flex items-center justify-between">
      <span :class="remaining < 0 ? 'text-red-600' : 'text-gray-500'" class="text-sm" aria-live="polite">
        {{ remaining }} caractères restants
      </span>
      <button type="submit" :disabled="!canSubmit"
              class="rounded bg-blue-600 px-4 py-2 font-semibold text-white disabled:opacity-50">
        {{ loading ? 'Publication…' : 'Publier' }}
      </button>
    </div>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
  </form>
</template>
```

### `src/components/ConfirmDialog.vue`

```vue
<script setup lang="ts">
defineProps<{ open: boolean; title: string; message: string; confirmLabel?: string; loading?: boolean }>()
defineEmits<{ confirm: []; cancel: [] }>()
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
       role="dialog" aria-modal="true" :aria-label="title" @keydown.esc="$emit('cancel')">
    <div class="w-full max-w-sm space-y-4 rounded-xl bg-white p-6 shadow-lg">
      <h2 class="text-lg font-bold">{{ title }}</h2>
      <p>{{ message }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="rounded border px-4 py-2" @click="$emit('cancel')">Annuler</button>
        <button type="button" :disabled="loading" class="rounded bg-red-600 px-4 py-2 text-white disabled:opacity-60"
                @click="$emit('confirm')">{{ confirmLabel ?? 'Confirmer' }}</button>
      </div>
    </div>
  </div>
</template>
```

### `src/components/PostCard.vue` (version de base — étendue aux ch. 5, 6, 9)

```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { deletePost } from '../services/posts'
import { errorMessage } from '../services/api'
import { useAuthStore } from '../stores/auth'
import { formatDate } from '../utils/format'
import UserAvatar from './UserAvatar.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import type { Post } from '../types'

const props = defineProps<{ post: Post }>()
const emit = defineEmits<{ deleted: [id: number] }>()

const auth = useAuthStore()
const isOwner = computed(() => auth.user?.id === props.post.user.id)
const confirming = ref(false)
const deleting = ref(false)
const error = ref('')

async function remove() {
  deleting.value = true
  error.value = ''
  try {
    await deletePost(props.post.id)
    emit('deleted', props.post.id)
  } catch (e) {
    error.value = errorMessage(e, 'Suppression impossible.')
  } finally {
    deleting.value = false
    confirming.value = false
  }
}
</script>

<template>
  <article class="space-y-3 rounded-xl bg-white p-4 shadow">
    <header class="flex items-center gap-3">
      <UserAvatar :name="post.user.name" :src="post.user.avatar_url" />
      <div class="flex-1">
        <RouterLink :to="{ name: 'profile', params: { id: post.user.id } }" class="font-semibold hover:underline">
          {{ post.user.name }}
        </RouterLink>
        <p class="text-xs text-gray-500">@{{ post.user.username }} · <time :datetime="post.created_at">{{ formatDate(post.created_at) }}</time></p>
      </div>
      <button v-if="isOwner" type="button" class="text-sm text-red-600 hover:underline" @click="confirming = true">
        Supprimer
      </button>
      <!-- Ch. 13 : <ReportButton target-type="post" :target-id="post.id" v-else-if="auth.user" /> -->
    </header>

    <!-- Texte brut : JAMAIS de v-html sur du contenu utilisateur -->
    <p class="whitespace-pre-wrap break-words">{{ post.content }}</p>

    <!-- Ch. 5 : bouton Like · Ch. 6 : bouton/section commentaires -->

    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>

    <ConfirmDialog :open="confirming" title="Supprimer la publication ?" message="Cette action est définitive."
                   confirm-label="Supprimer" :loading="deleting" @confirm="remove" @cancel="confirming = false" />
  </article>
</template>
```

### `src/views/FeedView.vue` (version du chapitre 4 — onglets au chapitre 8)

```vue
<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { fetchPosts, type FeedType } from '../services/posts'
import { errorMessage } from '../services/api'
import { useAuthStore } from '../stores/auth'
import PostCard from '../components/PostCard.vue'
import PostComposer from '../components/PostComposer.vue'
import LoadingState from '../components/LoadingState.vue'
import ErrorMessage from '../components/ErrorMessage.vue'
import type { Post } from '../types'

const auth = useAuthStore()
const feed = ref<FeedType>('global')
const posts = ref<Post[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
let requestId = 0   // ignore les réponses obsolètes (changement d'onglet rapide)

async function load(reset = false) {
  const current = ++requestId
  if (reset) { page.value = 1; posts.value = [] }
  loading.value = true
  error.value = ''
  try {
    const res = await fetchPosts(page.value, feed.value)
    if (current !== requestId) return
    const known = new Set(posts.value.map(p => p.id))
    posts.value.push(...res.data.filter(p => !known.has(p.id)))
    lastPage.value = res.meta.last_page
  } catch (e) {
    if (current === requestId) error.value = errorMessage(e, 'Impossible de charger le fil.')
  } finally {
    if (current === requestId) loading.value = false
  }
}

function loadMore() {
  if (page.value < lastPage.value && !loading.value) { page.value++; load() }
}

function onCreated(post: Post) { posts.value.unshift(post) }
function onDeleted(id: number) { posts.value = posts.value.filter(p => p.id !== id) }

onMounted(() => load(true))
watch(feed, () => load(true))
</script>

<template>
  <section class="space-y-4">
    <PostComposer v-if="auth.user" @created="onCreated" />
    <!-- Ch. 8 : onglets « Pour toi » / « Abonnements » -->

    <ErrorMessage v-if="error" :message="error" @retry="load(posts.length === 0)" />

    <PostCard v-for="post in posts" :key="post.id" :post="post" @deleted="onDeleted" />

    <LoadingState v-if="loading" />
    <p v-else-if="!posts.length && !error" class="py-8 text-center text-gray-500">Aucune publication pour le moment.</p>

    <button v-if="!loading && page < lastPage" type="button" class="mx-auto block rounded border px-4 py-2" @click="loadMore">
      Charger plus
    </button>
  </section>
</template>
```

### Ajout au profil (chapitre 3) : liste paginée des publications

Dans `ProfileView.vue`, `<script setup>` :

```ts
import { fetchUserPosts } from '../../services/users'
import PostCard from '../../components/PostCard.vue'
import type { Post } from '../../types'

const posts = ref<Post[]>([])
const postsPage = ref(1)
const postsLast = ref(1)
const postsLoading = ref(false)

async function loadPosts(reset = false) {
  if (reset) { postsPage.value = 1; posts.value = [] }
  postsLoading.value = true
  try {
    const res = await fetchUserPosts(String(route.params.id), postsPage.value)
    posts.value.push(...res.data)
    postsLast.value = res.meta.last_page
  } finally {
    postsLoading.value = false
  }
}
// Appeler loadPosts(true) à la fin de load() quand profile.value est défini
```

Dans le `<template>`, à la place du commentaire « Ch. 4 » :

```vue
<div class="space-y-4">
  <PostCard v-for="p in posts" :key="p.id" :post="p" @deleted="posts = posts.filter(x => x.id !== $event)" />
  <p v-if="!postsLoading && !posts.length" class="text-center text-gray-500">Aucune publication.</p>
  <button v-if="postsPage < postsLast" type="button" class="mx-auto block rounded border px-4 py-2"
          :disabled="postsLoading" @click="postsPage++; loadPosts()">Charger plus</button>
</div>
```

## 4.4 Tests

### `tests/Feature/PostTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_post(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/posts', ['content' => 'Hello TipTok'])
            ->assertCreated()
            ->assertJsonPath('data.content', 'Hello TipTok')
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('posts', ['user_id' => $user->id, 'content' => 'Hello TipTok']);
    }

    public function test_empty_or_too_long_content_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/posts', ['content' => ''])->assertJsonValidationErrors('content');
        $this->actingAs($user)->postJson('/api/v1/posts', ['content' => str_repeat('a', 501)])
            ->assertJsonValidationErrors('content');
    }

    public function test_guest_cannot_create_a_post(): void
    {
        $this->postJson('/api/v1/posts', ['content' => 'x'])->assertUnauthorized();
    }

    public function test_only_the_author_can_delete(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        $this->actingAs($other)->deleteJson("/api/v1/posts/{$post->id}")->assertForbidden();
        $this->assertDatabaseHas('posts', ['id' => $post->id]);

        $this->actingAs($author)->deleteJson("/api/v1/posts/{$post->id}")->assertOk();
        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_feed_is_paginated_and_includes_author(): void
    {
        Post::factory()->count(15)->create();

        $this->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['data' => [['id', 'content', 'user' => ['id', 'username'], 'likes_count', 'liked_by_me']]]);
    }

    public function test_user_posts_endpoint_only_returns_that_users_posts(): void
    {
        $a = User::factory()->create();
        Post::factory()->count(2)->create(['user_id' => $a->id]);
        Post::factory()->count(3)->create();

        $this->getJson("/api/v1/users/{$a->id}/posts")->assertOk()->assertJsonCount(2, 'data');
    }
}
```

### Frontend — `src/components/__tests__/PostComposer.spec.ts`

```ts
import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import PostComposer from '../PostComposer.vue'
import * as posts from '../../services/posts'

describe('PostComposer', () => {
  it('désactive le bouton si le texte est vide', () => {
    const w = mount(PostComposer)
    expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('affiche le compteur de caractères', async () => {
    const w = mount(PostComposer)
    await w.find('textarea').setValue('abc')
    expect(w.text()).toContain('497 caractères restants')
  })

  it('émet created et vide le champ après succès', async () => {
    vi.spyOn(posts, 'createPost').mockResolvedValue({ id: 1 } as never)
    const w = mount(PostComposer)
    await w.find('textarea').setValue('Hello')
    await w.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(w.emitted('created')).toHaveLength(1)
    expect((w.find('textarea').element as HTMLTextAreaElement).value).toBe('')
  })

  it('conserve la saisie en cas d\'erreur', async () => {
    vi.spyOn(posts, 'createPost').mockRejectedValue(new Error('boom'))
    const w = mount(PostComposer)
    await w.find('textarea').setValue('Hello')
    await w.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(w.find('[role="alert"]').exists()).toBe(true)
    expect((w.find('textarea').element as HTMLTextAreaElement).value).toBe('Hello')
  })
})
```

**Validation :** créer, lister, paginer, supprimer (auteur seulement) ; `php artisan test --filter=PostTest` vert.

---

# 5. Likes / Unlike

## 5.1 Base de données

Migration `create_likes_table` (modèle `Like` créé au chapitre 4) :

```php
Schema::create('likes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('post_id')->constrained()->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['user_id', 'post_id']);   // impossible de liker deux fois
    $table->index('post_id');
});
```

## 5.2 API

```bash
php artisan make:controller Api/V1/LikeController
```

`app/Http/Controllers/Api/V1/LikeController.php` :

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LikeController extends Controller
{
    public function store(Request $request, Post $post): JsonResponse
    {
        $result = $post->likedByUsers()->syncWithoutDetaching([$request->user()->id]);
        $changed = count($result['attached']) > 0;

        // Ch. 9 : if ($changed) PostLikedEvent::dispatch(...)  — voir §9.2

        return $this->respond($post, true, 'Publication aimée.');
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $changed = $post->likedByUsers()->detach($request->user()->id) > 0;

        // Ch. 9 : if ($changed) PostLikedEvent::dispatch(...)

        return $this->respond($post, false, 'Like retiré.');
    }

    private function respond(Post $post, bool $liked, string $message): JsonResponse
    {
        return response()->json([
            'success'     => true,
            'message'     => $message,
            'likes_count' => $post->likes()->count(),   // nombre réel après modification
            'liked'       => $liked,
        ]);
    }
}
```

> Les deux opérations sont **idempotentes** : liker deux fois ou retirer un like inexistant renvoie `200` avec l'état réel.

### Routes (groupe `auth:sanctum`)

```php
use App\Http\Controllers\Api\V1\LikeController;

Route::post('/posts/{post}/like', [LikeController::class, 'store'])->whereNumber('post');
Route::delete('/posts/{post}/like', [LikeController::class, 'destroy'])->whereNumber('post');
```

## 5.3 Frontend

`likePost` / `unlikePost` sont déjà dans `src/services/posts.ts` (§4.3).

### Extension de `PostCard.vue`

Dans `<script setup>` (ajouter) :

```ts
import { useRoute, useRouter } from 'vue-router'
import { likePost, unlikePost } from '../services/posts'

const router = useRouter()
const route = useRoute()

// États locaux : on ne mute jamais les props
const liked = ref(props.post.liked_by_me)
const likesCount = ref(props.post.likes_count)
const commentsCount = ref(props.post.comments_count)
const liking = ref(false)

watch(() => props.post, (p) => {          // resynchronisation si la liste est rechargée
  liked.value = p.liked_by_me
  likesCount.value = p.likes_count
  commentsCount.value = p.comments_count
})

async function toggleLike() {
  if (!auth.user) {
    await router.push({ name: 'login', query: { redirect: route.fullPath } })
    return
  }
  if (liking.value) return                // un clic à la fois par publication
  liking.value = true
  error.value = ''
  try {
    const res = liked.value ? await unlikePost(props.post.id) : await likePost(props.post.id)
    liked.value = res.liked
    likesCount.value = res.likes_count
  } catch (e) {
    error.value = errorMessage(e, 'Action impossible.')   // n'affecte que cette carte
  } finally {
    liking.value = false
  }
}
```

(ajouter `watch` à l'import de `vue`.)

Dans `<template>`, à la place du commentaire « Ch. 5 » :

```vue
<footer class="flex items-center gap-4 text-sm">
  <button type="button" :aria-pressed="liked" :disabled="liking"
          class="flex items-center gap-1 rounded px-2 py-1 hover:bg-gray-100 disabled:opacity-60"
          :class="liked ? 'font-semibold text-red-600' : 'text-gray-600'" @click="toggleLike">
    <span aria-hidden="true">{{ liked ? '♥' : '♡' }}</span>
    <span>{{ likesCount }}</span>
    <span class="sr-only">{{ liked ? 'Retirer le like' : 'Aimer' }}</span>
  </button>
  <!-- Ch. 6 : bouton commentaires -->
</footer>
```

## 5.4 Tests

### `tests/Feature/LikeTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LikeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_like_a_post(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/like")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('liked', true)
            ->assertJsonPath('likes_count', 1);

        $this->assertDatabaseHas('likes', ['user_id' => $user->id, 'post_id' => $post->id]);
    }

    public function test_liking_twice_does_not_duplicate(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/like");
        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/like")->assertJsonPath('likes_count', 1);

        $this->assertDatabaseCount('likes', 1);
    }

    public function test_unlike_is_idempotent(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->deleteJson("/api/v1/posts/{$post->id}/like")
            ->assertOk()->assertJsonPath('liked', false)->assertJsonPath('likes_count', 0);
    }

    public function test_guest_is_rejected(): void
    {
        $post = Post::factory()->create();
        $this->postJson("/api/v1/posts/{$post->id}/like")->assertUnauthorized();
    }

    public function test_liked_by_me_is_correct_in_the_feed(): void
    {
        $user = User::factory()->create();
        [$liked, $notLiked] = Post::factory()->count(2)->create();
        $liked->likedByUsers()->attach($user->id);

        $data = collect($this->actingAs($user)->getJson('/api/v1/posts')->json('data'))->keyBy('id');

        $this->assertTrue($data[$liked->id]['liked_by_me']);
        $this->assertFalse($data[$notLiked->id]['liked_by_me']);
    }
}
```

### Frontend — `src/components/__tests__/PostCard.like.spec.ts`

```ts
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PostCard from '../PostCard.vue'
import * as posts from '../../services/posts'
import { useAuthStore } from '../../stores/auth'

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
  useRoute: () => ({ fullPath: '/' }),
  RouterLink: { template: '<a><slot /></a>' },
}))

const post = {
  id: 1, content: 'Salut', created_at: '2026-01-01T10:00:00Z',
  user: { id: 2, name: 'Bob', username: 'bob', avatar_url: null },
  likes_count: 5, comments_count: 0, liked_by_me: false,
}

describe('PostCard — like', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().user = { id: 1, name: 'Al', username: 'al', email: 'a@a.a', avatar_url: null, bio: null }
  })

  it('met à jour compteur et état depuis la réponse API', async () => {
    vi.spyOn(posts, 'likePost').mockResolvedValue({ success: true, message: '', likes_count: 6, liked: true })
    const w = mount(PostCard, { props: { post }, global: { stubs: { ConfirmDialog: true } } })
    await w.find('button[aria-pressed]').trigger('click')
    await flushPromises()
    expect(w.find('button[aria-pressed]').attributes('aria-pressed')).toBe('true')
    expect(w.text()).toContain('6')
  })

  it('affiche une erreur sans casser la carte', async () => {
    vi.spyOn(posts, 'likePost').mockRejectedValue(new Error('network'))
    const w = mount(PostCard, { props: { post }, global: { stubs: { ConfirmDialog: true } } })
    await w.find('button[aria-pressed]').trigger('click')
    await flushPromises()
    expect(w.find('[role="alert"]').exists()).toBe(true)
    expect(w.text()).toContain('5')
  })
})
```

**Validation :** like/unlike met à jour le compteur ; un visiteur est redirigé vers `/login` ; plusieurs clics rapides ne créent qu'un like.

---

# 6. Commentaires

## 6.1 Base de données et modèle

```bash
php artisan make:model Comment -mf
php artisan make:policy CommentPolicy --model=Comment
php artisan make:controller Api/V1/CommentController
php artisan make:resource CommentResource
```

Migration `create_comments_table` :

```php
Schema::create('comments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('post_id')->constrained()->cascadeOnDelete();
    $table->text('content');
    $table->timestamps();
    $table->index(['post_id', 'created_at']);
});
```

`app/Models/Comment.php` :

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'post_id', 'content'];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function post(): BelongsTo { return $this->belongsTo(Post::class); }
}
```

`database/factories/CommentFactory.php` :

```php
public function definition(): array
{
    return [
        'user_id' => \App\Models\User::factory(),
        'post_id' => \App\Models\Post::factory(),
        'content' => fake()->sentence(),
    ];
}
```

`app/Policies/CommentPolicy.php` :

```php
public function delete(\App\Models\User $user, \App\Models\Comment $comment): bool
{
    return $user->id === $comment->user_id;
}
```

## 6.2 API

`app/Http/Resources/CommentResource.php` :

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'post_id'    => $this->post_id,
            'user_id'    => $this->user_id,
            'content'    => $this->content,
            'created_at' => $this->created_at?->toISOString(),
            'user'       => new UserBriefResource($this->whenLoaded('user')),
        ];
    }
}
```

`app/Http/Controllers/Api/V1/CommentController.php` :

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    /** GET /posts/{post}/comments — public, paginé (plus anciens d'abord) */
    public function index(Post $post)
    {
        $comments = $post->comments()
            ->with('user:id,name,username,avatar_url')
            ->orderBy('id')
            ->paginate(10);

        return CommentResource::collection($comments)->additional(['success' => true]);
    }

    /** POST /posts/{post}/comments */
    public function store(Request $request, Post $post): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:1000'],
        ]);

        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'content' => trim($validated['content']),
        ]);
        $comment->load('user:id,name,username,avatar_url');

        // Ch. 9 : NewCommentEvent::dispatch($comment);

        return (new CommentResource($comment))
            ->additional(['success' => true, 'message' => 'Commentaire ajouté.'])
            ->response()->setStatusCode(201);
    }

    /** DELETE /comments/{comment} — auteur uniquement */
    public function destroy(Comment $comment): JsonResponse
    {
        Gate::authorize('delete', $comment);
        $comment->delete();

        return response()->json(['success' => true, 'message' => 'Commentaire supprimé.']);
    }
}
```

### Routes

```php
use App\Http\Controllers\Api\V1\CommentController;

// Public
Route::get('/posts/{post}/comments', [CommentController::class, 'index'])->whereNumber('post');

// auth:sanctum
Route::post('/posts/{post}/comments', [CommentController::class, 'store'])->whereNumber('post');
Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->whereNumber('comment');
```

## 6.3 Frontend

### `src/services/comments.ts`

```ts
import { api } from './api'
import type { Paginated, PostComment } from '../types'

export async function fetchComments(postId: number, page = 1): Promise<Paginated<PostComment>> {
  const { data } = await api.get<Paginated<PostComment>>(`/api/v1/posts/${postId}/comments`, { params: { page } })
  return data
}

export async function createComment(postId: number, content: string): Promise<PostComment> {
  const { data } = await api.post<{ data: PostComment }>(`/api/v1/posts/${postId}/comments`, { content })
  return data.data
}

export async function deleteComment(commentId: number): Promise<void> {
  await api.delete(`/api/v1/comments/${commentId}`)
}
```

### `src/components/CommentSection.vue`

Chaque publication possède **son propre** état (liste, chargement, erreur) : une erreur sur un post n'affecte pas les autres.

```vue
<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { createComment, deleteComment, fetchComments } from '../services/comments'
import { errorMessage } from '../services/api'
import { useAuthStore } from '../stores/auth'
import { formatDate } from '../utils/format'
import UserAvatar from './UserAvatar.vue'
import type { PostComment } from '../types'

const props = defineProps<{ postId: number }>()
const emit = defineEmits<{ countChanged: [delta: number] }>()

const auth = useAuthStore()
const comments = ref<PostComment[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')
const content = ref('')
const sending = ref(false)

/** Ajoute les commentaires inconnus et renvoie combien ont réellement été ajoutés */
function mergeUnique(items: PostComment[]): number {
  const known = new Set(comments.value.map(c => c.id))
  const fresh = items.filter(c => !known.has(c.id))
  comments.value.push(...fresh)
  return fresh.length
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetchComments(props.postId, page.value)
    mergeUnique(res.data)
    lastPage.value = res.meta.last_page
  } catch (e) {
    error.value = errorMessage(e, 'Impossible de charger les commentaires.')
  } finally {
    loading.value = false
  }
}

function loadMore() { if (page.value < lastPage.value) { page.value++; load() } }

async function submit() {
  if (!content.value.trim() || sending.value) return
  sending.value = true
  error.value = ''
  try {
    const created = await createComment(props.postId, content.value)
    // Si l'événement temps réel l'a déjà ajouté, rien n'est ajouté ni compté deux fois
    if (mergeUnique([created]) > 0) emit('countChanged', +1)
    content.value = ''
  } catch (e) {
    error.value = errorMessage(e, 'Commentaire impossible.')
  } finally {
    sending.value = false
  }
}

async function remove(c: PostComment) {
  try {
    await deleteComment(c.id)
    comments.value = comments.value.filter(x => x.id !== c.id)
    emit('countChanged', -1)
  } catch (e) {
    error.value = errorMessage(e, 'Suppression impossible.')
  }
}

/** Utilisé par le temps réel (ch. 9) : ajout sans doublon */
function receive(comment: PostComment) {
  if (mergeUnique([comment]) > 0) emit('countChanged', +1)
}
defineExpose({ receive })

onMounted(load)
</script>

<template>
  <section class="space-y-3 border-t pt-3" aria-label="Commentaires">
    <ul class="space-y-3">
      <li v-for="c in comments" :key="c.id" class="flex items-start gap-2">
        <UserAvatar :name="c.user.name" :src="c.user.avatar_url" :size="28" />
        <div class="flex-1 rounded-lg bg-gray-100 px-3 py-2">
          <p class="text-xs text-gray-500">
            <strong>@{{ c.user.username }}</strong> · <time :datetime="c.created_at">{{ formatDate(c.created_at) }}</time>
          </p>
          <p class="whitespace-pre-wrap break-words text-sm">{{ c.content }}</p>
        </div>
        <button v-if="auth.user?.id === c.user_id" type="button" class="text-xs text-red-600 hover:underline"
                @click="remove(c)">Supprimer</button>
      </li>
    </ul>

    <p v-if="loading" role="status" class="text-sm text-gray-500">Chargement…</p>
    <p v-else-if="!comments.length && !error" class="text-sm text-gray-500">Aucun commentaire.</p>
    <button v-if="page < lastPage && !loading" type="button" class="text-sm underline" @click="loadMore">
      Charger plus de commentaires
    </button>

    <form v-if="auth.user" class="flex gap-2" @submit.prevent="submit">
      <label :for="`c-${postId}`" class="sr-only">Ajouter un commentaire</label>
      <input :id="`c-${postId}`" v-model="content" maxlength="1000" placeholder="Ajouter un commentaire…"
             class="flex-1 rounded border px-3 py-2 text-sm">
      <button type="submit" :disabled="sending || !content.trim()"
              class="rounded bg-blue-600 px-3 py-2 text-sm text-white disabled:opacity-50">Envoyer</button>
    </form>
    <p v-else class="text-sm text-gray-500">Connecte-toi pour commenter.</p>

    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
  </section>
</template>
```

### Intégration dans `PostCard.vue`

Script (ajouter) :

```ts
import CommentSection from './CommentSection.vue'

const commentsOpen = ref(false)
const commentSection = ref<InstanceType<typeof CommentSection> | null>(null)
```

Template, dans le `<footer>` à la place du commentaire « Ch. 6 » :

```vue
<button type="button" :aria-expanded="commentsOpen" class="rounded px-2 py-1 text-gray-600 hover:bg-gray-100"
        @click="commentsOpen = !commentsOpen">
  💬 {{ commentsCount }} <span class="sr-only">commentaires</span>
</button>
```

Après le `<footer>` :

```vue
<CommentSection v-if="commentsOpen" ref="commentSection" :post-id="post.id"
                @count-changed="commentsCount = Math.max(0, commentsCount + $event)" />
```

> `v-if` (et non `v-show`) : le composant — et, au chapitre 9, l'écoute temps réel — n'existe que lorsque le tiroir est ouvert.

## 6.4 Tests

### `tests/Feature/CommentTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_comment(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/comments", ['content' => 'Super !'])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('comments', ['post_id' => $post->id, 'user_id' => $user->id]);
    }

    public function test_invalid_content_is_rejected(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/comments", ['content' => ''])
            ->assertJsonValidationErrors('content');
        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/comments", ['content' => str_repeat('a', 1001)])
            ->assertJsonValidationErrors('content');
    }

    public function test_guest_cannot_comment(): void
    {
        $post = Post::factory()->create();
        $this->postJson("/api/v1/posts/{$post->id}/comments", ['content' => 'x'])->assertUnauthorized();
    }

    public function test_only_author_can_delete_comment(): void
    {
        $comment = Comment::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->deleteJson("/api/v1/comments/{$comment->id}")->assertForbidden();
        $this->actingAs($comment->user)->deleteJson("/api/v1/comments/{$comment->id}")->assertOk();
        $this->assertDatabaseMissing('comments', ['id' => $comment->id]);
    }

    public function test_comments_are_scoped_to_their_post(): void
    {
        $a = Post::factory()->create();
        $b = Post::factory()->create();
        Comment::factory()->count(2)->create(['post_id' => $a->id]);
        Comment::factory()->create(['post_id' => $b->id]);

        $this->getJson("/api/v1/posts/{$a->id}/comments")->assertOk()->assertJsonCount(2, 'data');
    }
}
```

**Validation :** ouvrir/fermer le tiroir, commenter sans recharger, supprimer son commentaire, « charger plus ».

---

# 7. Follow / Unfollow

## 7.1 Base de données

```bash
php artisan make:migration create_follows_table
php artisan make:controller Api/V1/FollowController
```

```php
Schema::create('follows', function (Blueprint $table) {
    $table->id();
    $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
    $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
    $table->timestamps();
    $table->unique(['follower_id', 'following_id']);
    $table->index('following_id');
});
```

Les relations `following()` / `followers()` sont dans `User` (§3.1).

## 7.2 API

`app/Http/Controllers/Api/V1/FollowController.php` :

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FollowController extends Controller
{
    public function store(Request $request, User $user): JsonResponse
    {
        $me = $request->user();

        if ($me->id === $user->id) {
            return response()->json(['success' => false, 'message' => 'Tu ne peux pas te suivre toi-même.'], 422);
        }

        // Transaction : relation + notification restent cohérentes
        $attached = DB::transaction(function () use ($me, $user) {
            $result = $me->following()->syncWithoutDetaching([$user->id]);
            $isNew = count($result['attached']) > 0;

            // Ch. 10 : if ($isNew) $user->notify(new TipTokNotification('follow', "{$me->name} a commencé à te suivre.", $me->id));
            return $isNew;
        });

        // Ch. 9 : if ($attached) UserFollowedEvent::dispatch($user->id, $me->id, $me->name, $me->username);

        return $this->respond($user, true, $attached ? 'Abonnement effectué.' : 'Déjà abonné.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $request->user()->following()->detach($user->id);

        return $this->respond($user, false, 'Désabonnement effectué.');
    }

    private function respond(User $target, bool $following, string $message): JsonResponse
    {
        return response()->json([
            'success'         => true,
            'message'         => $message,
            'is_following'    => $following,
            'followers_count' => $target->followers()->count(),
            'following_count' => $target->following()->count(),
        ]);
    }
}
```

### Routes (`auth:sanctum`)

```php
use App\Http\Controllers\Api\V1\FollowController;

Route::post('/users/{user}/follow', [FollowController::class, 'store'])->whereNumber('user');
Route::delete('/users/{user}/follow', [FollowController::class, 'destroy'])->whereNumber('user');
```

## 7.3 Frontend

### `src/services/follows.ts`

```ts
import { api } from './api'

export interface FollowResponse {
  success: boolean
  message: string
  is_following: boolean
  followers_count: number
  following_count: number
}

export async function followUser(id: number): Promise<FollowResponse> {
  const { data } = await api.post<FollowResponse>(`/api/v1/users/${id}/follow`)
  return data
}

export async function unfollowUser(id: number): Promise<FollowResponse> {
  const { data } = await api.delete<FollowResponse>(`/api/v1/users/${id}/follow`)
  return data
}
```

### Ajout à `ProfileView.vue`

Script :

```ts
import { useRouter } from 'vue-router'
import { followUser, unfollowUser } from '../../services/follows'

const router = useRouter()
const followLoading = ref(false)
const followError = ref('')
const isOwnProfile = computed(() => auth.user?.id === profile.value?.id)   // importer computed

async function toggleFollow() {
  if (!profile.value) return
  if (!auth.user) { await router.push({ name: 'login', query: { redirect: route.fullPath } }); return }
  followLoading.value = true
  followError.value = ''
  try {
    const res = profile.value.is_following
      ? await unfollowUser(profile.value.id)
      : await followUser(profile.value.id)
    profile.value.is_following = res.is_following
    profile.value.followers_count = res.followers_count   // compteurs depuis la réponse API
    profile.value.following_count = res.following_count
  } catch (e) {
    followError.value = errorMessage(e)
  } finally {
    followLoading.value = false
  }
}
```

Template (à la place du commentaire « Ch. 7 ») :

```vue
<button v-if="!isOwnProfile" type="button" :disabled="followLoading"
        class="mt-3 rounded px-4 py-2 font-semibold disabled:opacity-60"
        :class="profile.is_following ? 'border text-gray-700' : 'bg-blue-600 text-white'" @click="toggleFollow">
  {{ followLoading ? '…' : profile.is_following ? 'Se désabonner' : 'Suivre' }}
</button>
<p v-if="followError" role="alert" class="mt-2 text-sm text-red-600">{{ followError }}</p>
```

> Sur ton propre profil, les `following_count` / `followers_count` reflètent ce que renvoie l'API : ne les recalcule jamais localement.

## 7.4 Tests

### `tests/Feature/FollowTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_follow_and_unfollow(): void
    {
        [$me, $target] = User::factory()->count(2)->create();

        $this->actingAs($me)->postJson("/api/v1/users/{$target->id}/follow")
            ->assertOk()->assertJsonPath('is_following', true)->assertJsonPath('followers_count', 1);

        $this->actingAs($me)->deleteJson("/api/v1/users/{$target->id}/follow")
            ->assertOk()->assertJsonPath('is_following', false)->assertJsonPath('followers_count', 0);
    }

    public function test_cannot_follow_yourself(): void
    {
        $me = User::factory()->create();
        $this->actingAs($me)->postJson("/api/v1/users/{$me->id}/follow")->assertUnprocessable();
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_following_twice_does_not_duplicate(): void
    {
        [$me, $target] = User::factory()->count(2)->create();
        $this->actingAs($me)->postJson("/api/v1/users/{$target->id}/follow");
        $this->actingAs($me)->postJson("/api/v1/users/{$target->id}/follow")->assertOk();
        $this->assertDatabaseCount('follows', 1);
    }

    public function test_guest_is_rejected(): void
    {
        $target = User::factory()->create();
        $this->postJson("/api/v1/users/{$target->id}/follow")->assertUnauthorized();
    }
}
```

**Validation :** Suivre/Se désabonner met à jour les compteurs ; le bouton n'apparaît pas sur son propre profil.

---

# 8. Fil global et fil des abonnements

## 8.1 API

Déjà implémenté dans `PostController@index` (§4.2) :

- `GET /api/v1/posts?feed=global` — public ;
- `GET /api/v1/posts?feed=following` — `401` sans session, filtre `whereIn('user_id', $viewer->following()->select('users.id'))`.

Chaque publication contient `user`, `likes_count`, `comments_count` et `liked_by_me` (`false` pour un visiteur).

## 8.2 Frontend — onglets dans `FeedView.vue`

Script (ajouter) :

```ts
import { useRouter } from 'vue-router'

const router = useRouter()

async function selectFeed(next: FeedType) {
  if (next === 'following' && !auth.user) {
    await router.push({ name: 'login', query: { redirect: '/' } })   // visiteur → /login
    return
  }
  if (next !== feed.value) feed.value = next    // le watch(feed) recharge
}
```

Template (à la place du commentaire « Ch. 8 ») :

```vue
<div role="tablist" aria-label="Type de fil" class="flex rounded-xl bg-white p-1 shadow">
  <button type="button" role="tab" :aria-selected="feed === 'global'" class="flex-1 rounded-lg py-2 font-medium"
          :class="feed === 'global' ? 'bg-blue-600 text-white' : 'text-gray-600'" @click="selectFeed('global')">
    Pour toi
  </button>
  <button type="button" role="tab" :aria-selected="feed === 'following'" class="flex-1 rounded-lg py-2 font-medium"
          :class="feed === 'following' ? 'bg-blue-600 text-white' : 'text-gray-600'" @click="selectFeed('following')">
    Abonnements
  </button>
</div>
```

Le garde `requestId` (§4.3) garantit qu'une requête *following* qui échoue ou arrive tard n'écrase pas la liste globale ; les deux onglets ont une gestion d'erreur distincte (`error` est remis à zéro à chaque `load`).

Message vide spécifique (remplacer le texte du `<p v-else-if="!posts.length …">`) :

```vue
<p v-else-if="!posts.length && !error" class="py-8 text-center text-gray-500">
  {{ feed === 'following' ? "Suis des comptes pour voir leurs publications ici." : 'Aucune publication pour le moment.' }}
</p>
```

## 8.3 Tests

### `tests/Feature/FeedTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_feed_is_public(): void
    {
        Post::factory()->count(3)->create();
        $this->getJson('/api/v1/posts?feed=global')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_following_feed_requires_login(): void
    {
        $this->getJson('/api/v1/posts?feed=following')->assertUnauthorized();
    }

    public function test_following_feed_only_contains_followed_accounts(): void
    {
        [$me, $followed, $stranger] = User::factory()->count(3)->create();
        $me->following()->attach($followed->id);
        $mine = Post::factory()->create(['user_id' => $followed->id]);
        Post::factory()->create(['user_id' => $stranger->id]);

        $this->actingAs($me)->getJson('/api/v1/posts?feed=following')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_user_without_follows_gets_empty_list(): void
    {
        $me = User::factory()->create();
        Post::factory()->count(2)->create();
        $this->actingAs($me)->getJson('/api/v1/posts?feed=following')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_feed_value_is_rejected(): void
    {
        $this->getJson('/api/v1/posts?feed=hack')->assertUnprocessable();
    }
}
```

**Validation :** changer d'onglet recharge ; un visiteur cliquant « Abonnements » arrive sur `/login`.

---

# 9. Temps réel avec Laravel Reverb

## 9.1 Installation et configuration

```bash
cd ~/sites/app-tiptok/api-tiptok
php artisan install:broadcasting          # choisir Reverb si demandé
php artisan migrate                       # table jobs si QUEUE_CONNECTION=database

cd ~/sites/app-tiptok/frontend
npm install laravel-echo pusher-js
```

Backend `.env` (garde les valeurs générées par l'installateur) :

```dotenv
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database

REVERB_APP_ID=...
REVERB_APP_KEY=...
REVERB_APP_SECRET=...          # SECRET : jamais dans le frontend
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

Frontend `.env` (valeurs **publiques** uniquement) :

```dotenv
VITE_REVERB_APP_KEY=...
VITE_REVERB_HOST=127.0.0.1
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

Typage des variables — `src/env.d.ts` :

```ts
/// <reference types="vite/client" />
interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string
  readonly VITE_REVERB_APP_KEY: string
  readonly VITE_REVERB_HOST: string
  readonly VITE_REVERB_PORT: string
  readonly VITE_REVERB_SCHEME: 'http' | 'https'
}
interface ImportMeta { readonly env: ImportMetaEnv }
```

## 9.2 Événements Laravel

```bash
php artisan make:event PostLikedEvent
php artisan make:event NewCommentEvent
php artisan make:event UserFollowedEvent
```

Tous implémentent `ShouldBroadcast` (via la queue) **et** `ShouldDispatchAfterCommit` : l'événement n'est émis qu'une fois la transaction validée.

### `app/Events/PostLikedEvent.php`

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostLikedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $postId,
        public int $likesCount,
        public bool $liked,
        public int $actorId,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('post.' . $this->postId)];
    }

    public function broadcastAs(): string
    {
        return 'post.liked';
    }

    public function broadcastWith(): array
    {
        return [
            'post_id'     => $this->postId,
            'likes_count' => $this->likesCount,
            'liked'       => $this->liked,
            'actor_id'    => $this->actorId,
        ];
    }
}
```

### `app/Events/NewCommentEvent.php`

```php
<?php

namespace App\Events;

use App\Models\Comment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewCommentEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public Comment $comment) {}

    public function broadcastOn(): array
    {
        return [new Channel('post.' . $this->comment->post_id)];
    }

    public function broadcastAs(): string
    {
        return 'comment.created';
    }

    public function broadcastWith(): array
    {
        $comment = $this->comment->loadMissing('user:id,name,username,avatar_url');

        return [
            'comment' => [
                'id'         => $comment->id,
                'post_id'    => $comment->post_id,
                'user_id'    => $comment->user_id,
                'content'    => $comment->content,
                'created_at' => $comment->created_at?->toISOString(),
                'user'       => [
                    'id'         => $comment->user->id,
                    'name'       => $comment->user->name,
                    'username'   => $comment->user->username,
                    'avatar_url' => $comment->user->avatar_url,
                ],
            ],
        ];
    }
}
```

### `app/Events/UserFollowedEvent.php` (canal **privé** du destinataire)

> Par rapport au guide, ajout de `notificationId` : il permet au frontend de **dédupliquer** la notification
> temps réel avec la notification persistante (chapitre 10).

```php
<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserFollowedEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $recipientId,
        public int $followerId,
        public string $followerName,
        public string $followerUsername,
        public ?string $notificationId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.' . $this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'user.followed';
    }

    public function broadcastWith(): array
    {
        return [
            'type'            => 'follow',
            'message'         => $this->followerName . ' a commencé à te suivre.',
            'actor_id'        => $this->followerId,
            'actor_username'  => $this->followerUsername,
            'notification_id' => $this->notificationId,
        ];
    }
}
```

### `routes/channels.php`

```php
<?php

use Illuminate\Support\Facades\Broadcast;

// Seul le propriétaire peut écouter son canal de notifications
Broadcast::channel('users.{userId}', function ($user, int $userId) {
    return (int) $user->id === $userId;
});
```

L'authentification des canaux privés est exposée sur `POST /api/broadcasting/auth` (cf. `withBroadcasting` au §1.1), protégée par `auth:sanctum`.

### Déclenchement dans les contrôleurs

`LikeController` — émettre **seulement si quelque chose a changé** :

```php
use App\Events\PostLikedEvent;

public function store(Request $request, Post $post): JsonResponse
{
    $result  = $post->likedByUsers()->syncWithoutDetaching([$request->user()->id]);
    $changed = count($result['attached']) > 0;
    $count   = $post->likes()->count();

    if ($changed) {
        PostLikedEvent::dispatch($post->id, $count, true, $request->user()->id);
    }

    return response()->json(['success' => true, 'message' => 'Publication aimée.', 'likes_count' => $count, 'liked' => true]);
}

public function destroy(Request $request, Post $post): JsonResponse
{
    $changed = $post->likedByUsers()->detach($request->user()->id) > 0;
    $count   = $post->likes()->count();

    if ($changed) {
        PostLikedEvent::dispatch($post->id, $count, false, $request->user()->id);
    }

    return response()->json(['success' => true, 'message' => 'Like retiré.', 'likes_count' => $count, 'liked' => false]);
}
```

`CommentController@store` — après la création :

```php
use App\Events\NewCommentEvent;

NewCommentEvent::dispatch($comment);
```

`FollowController@store` — voir la version finale complète au §10.2 (notification persistante + événement).

## 9.3 Service Echo (instance unique)

`src/services/echo.ts` — configuration indicative : vérifie la version de `laravel-echo` installée.

```ts
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { api } from './api'

declare global { interface Window { Pusher: typeof Pusher } }
window.Pusher = Pusher

let instance: Echo<'reverb'> | null = null

/** Crée la connexion à la première utilisation puis la réutilise (une seule instance pour toute l'app). */
export function getEcho(): Echo<'reverb'> {
  if (instance) return instance

  const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http'
  const port = Number(import.meta.env.VITE_REVERB_PORT || 8080)

  instance = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST || '127.0.0.1',
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],

    // Auth des canaux privés avec les cookies Sanctum (instance axios : credentials + CSRF)
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data?: unknown) => void) => {
        api.post('/api/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
          .then(res => callback(null, res.data))
          .catch(err => callback(err as Error))
      },
    }),
  } as never)

  return instance
}

/** À appeler à la déconnexion de l'utilisateur. */
export function disconnectEcho(): void {
  instance?.disconnect()
  instance = null
}

export const realtimeEnabled = Boolean(import.meta.env.VITE_REVERB_APP_KEY)
```

> `realtimeEnabled` permet à l'application de fonctionner (sans temps réel) si Reverb n'est pas configuré.

## 9.4 Écoute côté Vue

### Types d'événements — à ajouter à `src/types/index.ts`

```ts
export interface PostLikedPayload { post_id: number; likes_count: number; liked: boolean; actor_id: number }
export interface CommentCreatedPayload { comment: PostComment }
export interface UserFollowedPayload {
  type: string; message: string; actor_id: number; actor_username: string; notification_id: string | null
}
```

### `src/composables/useRealtimePost.ts`

Un seul abonnement par publication ; l'écoute des commentaires n'existe que tiroir ouvert ; nettoyage garanti.

```ts
import { onBeforeUnmount, onMounted, watch, type Ref } from 'vue'
import { getEcho, realtimeEnabled } from '../services/echo'
import type { CommentCreatedPayload, PostLikedPayload } from '../types'

interface Handlers {
  onLiked: (event: PostLikedPayload) => void
  onComment: (event: CommentCreatedPayload) => void
}

export function useRealtimePost(postId: number, commentsOpen: Ref<boolean>, handlers: Handlers) {
  if (!realtimeEnabled) return

  const channelName = `post.${postId}`

  onMounted(() => {
    getEcho().channel(channelName).listen('.post.liked', handlers.onLiked)
  })

  watch(commentsOpen, (open) => {
    const channel = getEcho().channel(channelName)
    if (open) channel.listen('.comment.created', handlers.onComment)
    else channel.stopListening('.comment.created')          // fermeture du tiroir = arrêt de l'écoute
  })

  onBeforeUnmount(() => {
    getEcho().leave(channelName)                            // aucun listener orphelin
  })
}
```

### Intégration dans `PostCard.vue`

```ts
import { useAuthStore } from '../stores/auth'
import { useRealtimePost } from '../composables/useRealtimePost'
import type { CommentCreatedPayload, PostLikedPayload } from '../types'

useRealtimePost(props.post.id, commentsOpen, {
  onLiked(event: PostLikedPayload) {
    likesCount.value = event.likes_count
    // `liked` ne change que si l'acteur est l'utilisateur courant (autre onglet / autre appareil)
    if (auth.user?.id === event.actor_id) liked.value = event.liked
  },
  onComment(event: CommentCreatedPayload) {
    commentSection.value?.receive(event.comment)     // dédupliqué par id dans CommentSection
  },
})
```

> `commentsOpen` et `commentSection` sont déclarés au chapitre 6 : place cet appel **après** leur déclaration.

## 9.5 Queue et diffusion

```bash
php artisan reverb:start --debug
php artisan queue:work
```

Les événements implémentent `ShouldBroadcast` : sans `queue:work`, rien n'est diffusé. Pour un tout premier test uniquement, tu peux remplacer temporairement `ShouldBroadcast` par `ShouldBroadcastNow` (diffusion synchrone), puis **revenir à `ShouldBroadcast`** et tester avec la queue avant la mise en production.

## 9.6 Tests

### `phpunit.xml` — configuration de test (clés factices, aucune connexion réseau requise)

```xml
<env name="BROADCAST_CONNECTION" value="reverb"/>
<env name="REVERB_APP_ID" value="test"/>
<env name="REVERB_APP_KEY" value="test-key"/>
<env name="REVERB_APP_SECRET" value="test-secret"/>
<env name="QUEUE_CONNECTION" value="sync"/>
```

### `tests/Feature/RealtimeTest.php`

```php
<?php

namespace Tests\Feature;

use App\Events\NewCommentEvent;
use App\Events\PostLikedEvent;
use App\Events\UserFollowedEvent;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_like_dispatches_event_with_full_payload(): void
    {
        Event::fake([PostLikedEvent::class]);
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/like");

        Event::assertDispatched(PostLikedEvent::class, fn ($e) =>
            $e->postId === $post->id && $e->likesCount === 1 && $e->liked === true && $e->actorId === $user->id
        );
    }

    public function test_repeated_like_does_not_dispatch_again(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();
        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/like");

        Event::fake([PostLikedEvent::class]);
        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/like");

        Event::assertNotDispatched(PostLikedEvent::class);
    }

    public function test_comment_event_contains_comment_and_author(): void
    {
        $comment = Comment::factory()->create();
        $event = new NewCommentEvent($comment);

        $this->assertEquals([new Channel('post.' . $comment->post_id)], $event->broadcastOn());
        $this->assertSame($comment->user->username, $event->broadcastWith()['comment']['user']['username']);
    }

    public function test_follow_event_uses_recipient_private_channel(): void
    {
        $event = new UserFollowedEvent(7, 1, 'Alice', 'alice');

        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-users.7', $event->broadcastOn()[0]->name);
    }

    public function test_user_cannot_authorize_another_users_private_channel(): void
    {
        [$me, $other] = User::factory()->count(2)->create();

        $this->actingAs($me)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678', 'channel_name' => 'private-users.' . $other->id,
        ])->assertForbidden();

        $this->actingAs($me)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678', 'channel_name' => 'private-users.' . $me->id,
        ])->assertOk();
    }
}
```

### Tests manuels temps réel (deux navigateurs / deux comptes)

| Scénario | Résultat attendu |
|---|---|
| A like un post visible chez B | Le compteur chez B change **sans rafraîchir** |
| A commente, B a le tiroir ouvert | Le commentaire apparaît chez B ; **un seul** exemplaire |
| A suit B | B voit une notification instantanée |
| B ferme le tiroir | Plus d'écoute (`reverb:start --debug` ne montre plus d'abonnement) |
| Coupure réseau puis retour | Aucun commentaire en double |
| Navigation vers une autre page | Les listeners sont retirés (`echo.leave`) |

**Validation :** `php artisan test --filter=RealtimeTest` vert + les 6 scénarios manuels.

---

# 10. Notifications persistantes

Une notification de suivi est **enregistrée en base** (consultable hors ligne) **et** diffusée en temps réel.

## 10.1 Base de données et notification

```bash
php artisan make:notifications-table      # puis : php artisan migrate
php artisan make:notification TipTokNotification
php artisan make:controller Api/V1/NotificationController
```

### `app/Notifications/TipTokNotification.php`

```php
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TipTokNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,
        public string $message,
        public ?int $actorId = null,
        public ?int $postId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];   // le temps réel est géré par UserFollowedEvent
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'     => $this->type,
            'message'  => $this->message,
            'actor_id' => $this->actorId,
            'post_id'  => $this->postId,
        ];
    }
}
```

`User` utilise déjà `Notifiable` (§3.1).

## 10.2 API

### `FollowController@store` — version finale (persistance + temps réel)

```php
use App\Events\UserFollowedEvent;
use App\Notifications\TipTokNotification;

public function store(Request $request, User $user): JsonResponse
{
    $me = $request->user();

    if ($me->id === $user->id) {
        return response()->json(['success' => false, 'message' => 'Tu ne peux pas te suivre toi-même.'], 422);
    }

    $notificationId = null;

    $isNew = DB::transaction(function () use ($me, $user, &$notificationId) {
        $result = $me->following()->syncWithoutDetaching([$user->id]);
        $isNew = count($result['attached']) > 0;

        if ($isNew) {   // on ne notifie qu'un NOUVEL abonnement
            $notification = new TipTokNotification('follow', "{$me->name} a commencé à te suivre.", $me->id);
            $user->notify($notification);
            $notificationId = $notification->id;   // renseigné par le système de notifications
        }

        return $isNew;
    });

    if ($isNew) {
        UserFollowedEvent::dispatch($user->id, $me->id, $me->name, $me->username, $notificationId);
    }

    return $this->respond($user, true, $isNew ? 'Abonnement effectué.' : 'Déjà abonné.');
}
```

### `app/Http/Controllers/Api/V1/NotificationController.php`

Toutes les requêtes passent par `$request->user()->notifications()` : impossible d'accéder aux notifications d'un autre compte.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /** GET /notifications */
    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->notifications()->latest()->paginate(20);
        $page->through(fn (DatabaseNotification $n) => $this->format($n));

        return response()->json([
            'success'      => true,
            'data'         => $page->items(),
            'meta'         => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** GET /notifications/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** PATCH /notifications/{id}/read */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** POST /notifications/read-all */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'unread_count' => 0]);
    }

    private function format(DatabaseNotification $n): array
    {
        return [
            'id'         => $n->id,
            'type'       => $n->data['type'] ?? 'unknown',
            'message'    => $n->data['message'] ?? '',
            'actor_id'   => $n->data['actor_id'] ?? null,
            'post_id'    => $n->data['post_id'] ?? null,
            'read_at'    => $n->read_at?->toISOString(),
            'created_at' => $n->created_at?->toISOString(),
        ];
    }
}
```

### Routes (`auth:sanctum`)

```php
use App\Http\Controllers\Api\V1\NotificationController;

Route::get('/notifications', [NotificationController::class, 'index']);
Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id');
```

## 10.3 Frontend

### `src/services/notifications.ts`

```ts
import { api } from './api'
import type { AppNotification } from '../types'

export interface NotificationsPage {
  success: boolean
  data: AppNotification[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  unread_count: number
}

export async function fetchNotifications(page = 1): Promise<NotificationsPage> {
  const { data } = await api.get<NotificationsPage>('/api/v1/notifications', { params: { page } })
  return data
}

export async function fetchUnreadCount(): Promise<number> {
  const { data } = await api.get<{ unread_count: number }>('/api/v1/notifications/unread-count')
  return data.unread_count
}

export async function markNotificationRead(id: string): Promise<number> {
  const { data } = await api.patch<{ unread_count: number }>(`/api/v1/notifications/${id}/read`)
  return data.unread_count
}

export async function markAllNotificationsRead(): Promise<void> {
  await api.post('/api/v1/notifications/read-all')
}
```

### `src/stores/notifications.ts`

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as service from '../services/notifications'
import { errorMessage } from '../services/api'
import { getEcho, realtimeEnabled } from '../services/echo'
import type { AppNotification, UserFollowedPayload } from '../types'

export const useNotificationsStore = defineStore('notifications', () => {
  const items = ref<AppNotification[]>([])
  const unread = ref(0)
  const page = ref(1)
  const lastPage = ref(1)
  const loading = ref(false)
  const error = ref('')
  let listeningFor: number | null = null

  async function fetch(reset = true) {
    if (reset) { page.value = 1 }
    loading.value = true
    error.value = ''
    try {
      const res = await service.fetchNotifications(page.value)
      const known = new Set(reset ? [] : items.value.map(n => n.id))
      items.value = reset ? res.data : [...items.value, ...res.data.filter(n => !known.has(n.id))]
      lastPage.value = res.meta.last_page
      unread.value = res.unread_count
    } catch (e) {
      error.value = errorMessage(e, 'Impossible de charger les notifications.')
    } finally {
      loading.value = false
    }
  }

  async function loadMore() {
    if (page.value >= lastPage.value || loading.value) return
    page.value++
    await fetch(false)
  }

  async function refreshUnread() {
    try { unread.value = await service.fetchUnreadCount() } catch { /* silencieux */ }
  }

  async function markRead(id: string) {
    const target = items.value.find(n => n.id === id)
    if (!target || target.read_at) return
    try {
      unread.value = await service.markNotificationRead(id)
      target.read_at = new Date().toISOString()
    } catch (e) {
      error.value = errorMessage(e)
    }
  }

  async function markAllRead() {
    try {
      await service.markAllNotificationsRead()
      const now = new Date().toISOString()
      items.value.forEach(n => { n.read_at ??= now })
      unread.value = 0
    } catch (e) {
      error.value = errorMessage(e)
    }
  }

  /** Réception temps réel — dédupliquée par notification_id */
  function receive(event: UserFollowedPayload) {
    if (event.notification_id && items.value.some(n => n.id === event.notification_id)) return
    items.value.unshift({
      id: event.notification_id ?? `rt-${Date.now()}`,
      type: event.type,
      message: event.message,
      actor_id: event.actor_id,
      post_id: null,
      read_at: null,
      created_at: new Date().toISOString(),
    })
    unread.value++
  }

  function start(userId: number) {
    refreshUnread()
    if (!realtimeEnabled || listeningFor === userId) return
    stop()
    getEcho().private(`users.${userId}`).listen('.user.followed', receive)
    listeningFor = userId
  }

  function stop() {
    if (listeningFor !== null) {
      getEcho().leave(`users.${listeningFor}`)
      listeningFor = null
    }
    items.value = []
    unread.value = 0
  }

  return { items, unread, page, lastPage, loading, error, fetch, loadMore, refreshUnread, markRead, markAllRead, receive, start, stop }
})
```

### Démarrage / arrêt de l'écoute — `src/App.vue` (version finale)

```vue
<script setup lang="ts">
import { watch } from 'vue'
import AppHeader from './components/AppHeader.vue'
import { useAuthStore } from './stores/auth'
import { useNotificationsStore } from './stores/notifications'
import { disconnectEcho, realtimeEnabled } from './services/echo'

const auth = useAuthStore()
const notifications = useNotificationsStore()

watch(() => auth.user?.id, (id, previous) => {
  if (id) {
    notifications.start(id)
  } else if (previous) {
    notifications.stop()
    if (realtimeEnabled) disconnectEcho()    // déconnexion : ferme la connexion WebSocket
  }
}, { immediate: true })
</script>

<template>
  <div class="min-h-screen bg-gray-50 text-gray-900">
    <AppHeader />
    <main class="mx-auto max-w-2xl px-4 py-6">
      <RouterView />
    </main>
  </div>
</template>
```

### `src/components/AppHeader.vue` (version finale, avec compteur non lu)

```vue
<script setup lang="ts">
import { useRouter, RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useNotificationsStore } from '../stores/notifications'

const auth = useAuthStore()
const notifications = useNotificationsStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  await router.push({ name: 'login' })
}
</script>

<template>
  <header class="sticky top-0 z-40 border-b bg-white">
    <nav class="mx-auto flex max-w-2xl items-center gap-4 px-4 py-3" aria-label="Navigation principale">
      <RouterLink to="/" class="text-xl font-extrabold text-blue-600">TipTok</RouterLink>
      <RouterLink to="/search" class="ml-auto hover:underline">Recherche</RouterLink>

      <template v-if="auth.user">
        <RouterLink to="/notifications" class="relative hover:underline">
          Notifications
          <span v-if="notifications.unread > 0" aria-live="polite"
                class="absolute -right-4 -top-2 rounded-full bg-red-600 px-1.5 text-xs font-bold text-white">
            {{ notifications.unread > 99 ? '99+' : notifications.unread }}
            <span class="sr-only">non lues</span>
          </span>
        </RouterLink>
        <RouterLink :to="{ name: 'profile', params: { id: auth.user.id } }" class="hover:underline">Profil</RouterLink>
        <button type="button" class="text-gray-600 hover:underline" @click="logout">Déconnexion</button>
      </template>
      <template v-else>
        <RouterLink to="/login" class="hover:underline">Connexion</RouterLink>
        <RouterLink to="/register" class="rounded bg-blue-600 px-3 py-1 text-white">Inscription</RouterLink>
      </template>
    </nav>
  </header>
</template>
```

### `src/views/notifications/NotificationsView.vue`

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationsStore } from '../../stores/notifications'
import { formatDate } from '../../utils/format'
import LoadingState from '../../components/LoadingState.vue'
import ErrorMessage from '../../components/ErrorMessage.vue'
import type { AppNotification } from '../../types'

const store = useNotificationsStore()
const router = useRouter()

onMounted(() => store.fetch(true))

async function open(n: AppNotification) {
  await store.markRead(n.id)
  if (n.actor_id) await router.push({ name: 'profile', params: { id: n.actor_id } })
}
</script>

<template>
  <section class="space-y-4">
    <header class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">Notifications</h1>
      <button v-if="store.unread > 0" type="button" class="text-sm text-blue-600 underline" @click="store.markAllRead()">
        Tout marquer comme lu
      </button>
    </header>

    <ErrorMessage v-if="store.error" :message="store.error" @retry="store.fetch(true)" />

    <ul class="space-y-2">
      <li v-for="n in store.items" :key="n.id">
        <button type="button" class="w-full rounded-xl p-4 text-left shadow"
                :class="n.read_at ? 'bg-white' : 'border-l-4 border-blue-600 bg-blue-50'" @click="open(n)">
          <p :class="{ 'font-semibold': !n.read_at }">{{ n.message }}</p>
          <p class="text-xs text-gray-500">{{ formatDate(n.created_at) }}</p>
        </button>
      </li>
    </ul>

    <LoadingState v-if="store.loading" />
    <p v-else-if="!store.items.length && !store.error" class="py-8 text-center text-gray-500">Aucune notification.</p>

    <button v-if="!store.loading && store.page < store.lastPage" type="button"
            class="mx-auto block rounded border px-4 py-2" @click="store.loadMore()">Charger plus</button>
  </section>
</template>
```

## 10.4 Tests

### `tests/Feature/NotificationTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TipTokNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_follow_creates_a_persistent_notification_for_the_target(): void
    {
        [$me, $target] = User::factory()->count(2)->create();

        $this->actingAs($me)->postJson("/api/v1/users/{$target->id}/follow")->assertOk();

        $this->assertSame(1, $target->notifications()->count());
        $this->assertSame('follow', $target->notifications()->first()->data['type']);
        $this->assertSame(0, $me->notifications()->count());
    }

    public function test_refollowing_does_not_create_a_second_notification(): void
    {
        [$me, $target] = User::factory()->count(2)->create();
        $this->actingAs($me)->postJson("/api/v1/users/{$target->id}/follow");
        $this->actingAs($me)->postJson("/api/v1/users/{$target->id}/follow");

        $this->assertSame(1, $target->notifications()->count());
    }

    public function test_user_only_sees_their_own_notifications(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $a->notify(new TipTokNotification('follow', 'pour A'));
        $b->notify(new TipTokNotification('follow', 'pour B'));

        $this->actingAs($a)->getJson('/api/v1/notifications')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'pour A')
            ->assertJsonPath('unread_count', 1);
    }

    public function test_cannot_mark_someone_elses_notification_as_read(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $b->notify(new TipTokNotification('follow', 'pour B'));
        $id = $b->notifications()->first()->id;

        $this->actingAs($a)->patchJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    }

    public function test_mark_as_read_and_read_all_update_the_counter(): void
    {
        $user = User::factory()->create();
        $user->notify(new TipTokNotification('follow', '1'));
        $user->notify(new TipTokNotification('follow', '2'));
        $id = $user->notifications()->first()->id;

        $this->actingAs($user)->patchJson("/api/v1/notifications/{$id}/read")->assertJsonPath('unread_count', 1);
        $this->actingAs($user)->postJson('/api/v1/notifications/read-all')->assertJsonPath('unread_count', 0);
        $this->actingAs($user)->getJson('/api/v1/notifications/unread-count')->assertJsonPath('unread_count', 0);
    }

    public function test_notifications_are_paginated(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 25) as $i) { $user->notify(new TipTokNotification('follow', "n{$i}")); }

        $this->actingAs($user)->getJson('/api/v1/notifications')
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 2);
    }
}
```

### Frontend — `src/stores/__tests__/notifications.spec.ts`

```ts
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../services/echo', () => ({ getEcho: vi.fn(), realtimeEnabled: false }))

import { useNotificationsStore } from '../notifications'

const event = { type: 'follow', message: 'Bob a commencé à te suivre.', actor_id: 2, actor_username: 'bob', notification_id: 'abc' }

describe('notifications store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('ajoute une notification temps réel et incrémente le compteur', () => {
    const s = useNotificationsStore()
    s.receive(event)
    expect(s.items).toHaveLength(1)
    expect(s.unread).toBe(1)
  })

  it('ne crée pas de doublon pour le même notification_id', () => {
    const s = useNotificationsStore()
    s.receive(event)
    s.receive(event)
    expect(s.items).toHaveLength(1)
    expect(s.unread).toBe(1)
  })
})
```

**Validation :** suivre un compte crée une notification en base ET apparaît instantanément chez la cible ; après reconnexion elle est toujours listée ; le compteur du header est exact.

---

# 11. Recherche utilisateurs et publications

## 11.1 API

```bash
php artisan make:controller Api/V1/SearchController
```

### Scope réutilisable — `app/Models/Post.php` (ajout)

```php
use Illuminate\Database\Eloquent\Builder;

/** Auteur + compteurs + état « liké par moi » pour le spectateur éventuel */
public function scopeWithFeedData(Builder $query, ?User $viewer): Builder
{
    $query->with('user:id,name,username,avatar_url')->withCount(['likes', 'comments']);

    if ($viewer) {
        $query->withExists(['likes as liked_by_me' => fn ($q) => $q->where('user_id', $viewer->id)]);
    }

    return $query;
}
```

(`PostController::baseQuery()` peut alors se réduire à `Post::query()->withFeedData($viewer)->latest('id')`.)

### `app/Http/Controllers/Api/V1/SearchController.php`

> La requête des publications est écrite **correctement** (`->where('content', 'like', $like)`) : c'est la
> correction signalée dans le guide (§11.1). Vérifie quand même la syntaxe avec `php -l`.

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserBriefResource;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        // Les caractères spéciaux LIKE (% _ \) sont échappés : « 100% » ne devient pas un joker
        $term = addcslashes(trim($validated['q']), '%_\\');
        $like = "%{$term}%";

        $users = User::query()
            ->where(function ($q) use ($like) {
                $q->where('username', 'like', $like)->orWhere('name', 'like', $like);
            })
            ->orderBy('username')
            ->limit(10)
            ->get(['id', 'name', 'username', 'avatar_url']);   // aucune donnée privée

        $posts = Post::query()
            ->withFeedData($request->user('sanctum'))
            ->where('content', 'like', $like)
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'users' => UserBriefResource::collection($users),
                'posts' => PostResource::collection($posts),
            ],
        ]);
    }
}
```

### Route (publique)

```php
use App\Http\Controllers\Api\V1\SearchController;

Route::get('/search', SearchController::class);   // + throttle:search au chapitre 13
```

## 11.2 Frontend

### `src/services/search.ts`

```ts
import { api } from './api'
import type { Post, UserBrief } from '../types'

export interface SearchResults { users: UserBrief[]; posts: Post[] }

export async function search(q: string, signal?: AbortSignal): Promise<SearchResults> {
  const { data } = await api.get<{ success: boolean; data: SearchResults }>('/api/v1/search', {
    params: { q }, signal,
  })
  return data.data
}
```

### `src/composables/useDebounce.ts`

```ts
import { ref, watch, type Ref } from 'vue'

export function useDebounce<T>(source: Ref<T>, delay = 300): Ref<T> {
  const debounced = ref(source.value) as Ref<T>
  let timer: ReturnType<typeof setTimeout> | undefined

  watch(source, (value) => {
    clearTimeout(timer)
    timer = setTimeout(() => { debounced.value = value }, delay)
  })

  return debounced
}
```

### `src/views/SearchView.vue`

```vue
<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import { search, type SearchResults } from '../services/search'
import { errorMessage } from '../services/api'
import { useDebounce } from '../composables/useDebounce'
import PostCard from '../components/PostCard.vue'
import UserAvatar from '../components/UserAvatar.vue'
import LoadingState from '../components/LoadingState.vue'
import ErrorMessage from '../components/ErrorMessage.vue'

const route = useRoute()
const router = useRouter()

const query = ref(typeof route.query.q === 'string' ? route.query.q : '')
const debounced = useDebounce(query, 300)
const tab = ref<'users' | 'posts'>('users')
const results = ref<SearchResults>({ users: [], posts: [] })
const loading = ref(false)
const error = ref('')
const searched = ref(false)
let controller: AbortController | null = null

const tooShort = computed(() => debounced.value.trim().length < 2)

async function run() {
  controller?.abort()                                   // annule la requête précédente
  if (tooShort.value) { results.value = { users: [], posts: [] }; searched.value = false; return }

  controller = new AbortController()
  loading.value = true
  error.value = ''
  try {
    results.value = await search(debounced.value.trim(), controller.signal)
    searched.value = true
    router.replace({ query: { q: debounced.value.trim() } })
  } catch (e) {
    if (!axios.isCancel(e)) error.value = errorMessage(e, 'Recherche impossible.')
  } finally {
    loading.value = false
  }
}

watch(debounced, run, { immediate: true })
onBeforeUnmount(() => controller?.abort())
</script>

<template>
  <section class="space-y-4">
    <h1 class="text-2xl font-bold">Recherche</h1>

    <label for="q" class="sr-only">Rechercher des utilisateurs ou des publications</label>
    <input id="q" v-model="query" type="search" placeholder="Rechercher…" maxlength="100" autocomplete="off"
           class="w-full rounded-xl border bg-white p-3 shadow">
    <p v-if="query && tooShort" class="text-sm text-gray-500">Saisis au moins 2 caractères.</p>

    <div role="tablist" class="flex rounded-xl bg-white p-1 shadow">
      <button type="button" role="tab" :aria-selected="tab === 'users'" class="flex-1 rounded-lg py-2"
              :class="tab === 'users' ? 'bg-blue-600 text-white' : 'text-gray-600'" @click="tab = 'users'">
        Utilisateurs ({{ results.users.length }})
      </button>
      <button type="button" role="tab" :aria-selected="tab === 'posts'" class="flex-1 rounded-lg py-2"
              :class="tab === 'posts' ? 'bg-blue-600 text-white' : 'text-gray-600'" @click="tab = 'posts'">
        Publications ({{ results.posts.length }})
      </button>
    </div>

    <LoadingState v-if="loading" />
    <ErrorMessage v-else-if="error" :message="error" @retry="run" />

    <template v-else-if="searched">
      <ul v-if="tab === 'users'" class="space-y-2">
        <li v-for="u in results.users" :key="u.id">
          <RouterLink :to="{ name: 'profile', params: { id: u.id } }"
                      class="flex items-center gap-3 rounded-xl bg-white p-3 shadow hover:bg-gray-50">
            <UserAvatar :name="u.name" :src="u.avatar_url" />
            <span><strong>{{ u.name }}</strong> <span class="text-gray-500">@{{ u.username }}</span></span>
          </RouterLink>
        </li>
        <li v-if="!results.users.length" class="py-6 text-center text-gray-500">Aucun utilisateur trouvé.</li>
      </ul>

      <div v-else class="space-y-3">
        <PostCard v-for="p in results.posts" :key="p.id" :post="p"
                  @deleted="results.posts = results.posts.filter(x => x.id !== $event)" />
        <p v-if="!results.posts.length" class="py-6 text-center text-gray-500">Aucune publication trouvée.</p>
      </div>
    </template>
  </section>
</template>
```

## 11.3 Tests

### `tests/Feature/SearchTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_is_required_and_min_two_chars(): void
    {
        $this->getJson('/api/v1/search')->assertUnprocessable();
        $this->getJson('/api/v1/search?q=a')->assertUnprocessable();
    }

    public function test_finds_users_by_username_and_name(): void
    {
        User::factory()->create(['username' => 'laravelfan', 'name' => 'Zoe']);
        User::factory()->create(['username' => 'other', 'name' => 'Laravel Lover']);
        User::factory()->create(['username' => 'nomatch', 'name' => 'Nobody']);

        $this->getJson('/api/v1/search?q=laravel')->assertOk()->assertJsonCount(2, 'data.users');
    }

    public function test_finds_posts_by_content(): void
    {
        Post::factory()->create(['content' => 'Apprendre Vue 3 est génial']);
        Post::factory()->create(['content' => 'Autre sujet']);

        $this->getJson('/api/v1/search?q=Vue')->assertOk()->assertJsonCount(1, 'data.posts');
    }

    public function test_results_are_limited(): void
    {
        Post::factory()->count(30)->create(['content' => 'motcle commun']);
        User::factory()->count(15)->create(['username' => fn () => 'motcle' . fake()->unique()->numberBetween(1, 9999)]);

        $json = $this->getJson('/api/v1/search?q=motcle')->assertOk()->json('data');
        $this->assertCount(20, $json['posts']);
        $this->assertCount(10, $json['users']);
    }

    public function test_percent_sign_is_not_a_wildcard(): void
    {
        Post::factory()->create(['content' => 'abc']);
        $this->getJson('/api/v1/search?q=%25%25')->assertOk()->assertJsonCount(0, 'data.posts');
    }

    public function test_no_private_data_leaks(): void
    {
        User::factory()->create(['username' => 'secretuser']);
        $user = $this->getJson('/api/v1/search?q=secretuser')->json('data.users.0');

        $this->assertEqualsCanonicalizing(['id', 'name', 'username', 'avatar_url'], array_keys($user));
    }
}
```

**Validation :** la saisie déclenche une seule requête après ~300 ms ; effacer le champ vide les résultats ; les liens ouvrent profils/publications.

---

# 12. Modifier le profil et l'avatar

## 12.1 API

```bash
php artisan make:controller Api/V1/ProfileController
php artisan storage:link        # expose storage/app/public via /storage
```

### `app/Http/Controllers/Api/V1/ProfileController.php`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /** PATCH /profile — l'utilisateur modifie UNIQUEMENT son propre profil (aucun id en entrée) */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'alpha_dash', 'min:3', 'max:30',
                           Rule::unique('users', 'username')->ignore($user->id)],
            'bio'      => ['nullable', 'string', 'max:300'],
        ]);

        $validated['username'] = strtolower($validated['username']);

        $user->update($validated);   // $fillable protège les autres colonnes (role, email…)

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour.',
            'data'    => new UserResource($user->fresh()),
        ]);
    }

    /** POST /profile/avatar */
    public function avatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048',
                         'dimensions:max_width=4000,max_height=4000'],
        ]);

        $user = $request->user();
        $oldPath = $user->avatar_path;

        // Nom généré par Laravel : le nom envoyé par le client n'est jamais utilisé
        $path = $request->file('avatar')->store('avatars', 'public');

        $user->update([
            'avatar_path' => $path,
            'avatar_url'  => Storage::disk('public')->url($path),
        ]);

        // L'ancien fichier n'est supprimé qu'après le succès de la mise à jour
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Avatar mis à jour.',
            'data'    => new UserResource($user->fresh()),
        ]);
    }
}
```

### Routes (`auth:sanctum`)

```php
use App\Http\Controllers\Api\V1\ProfileController;

Route::patch('/profile', [ProfileController::class, 'update']);
Route::post('/profile/avatar', [ProfileController::class, 'avatar']);
```

> En production, remplace le disque `public` par un disque S3 compatible (`config/filesystems.php`) et ajuste `Storage::disk(...)`.

## 12.2 Frontend

### `src/services/users.ts` (ajouts)

```ts
import type { AuthUser } from '../types'

export interface ProfilePayload { name: string; username: string; bio: string | null }

export async function updateProfile(payload: ProfilePayload): Promise<AuthUser> {
  const { data } = await api.patch<{ data: AuthUser }>('/api/v1/profile', payload)
  return data.data
}

export async function uploadAvatar(file: File): Promise<AuthUser> {
  const form = new FormData()
  form.append('avatar', file)
  // Pas de Content-Type manuel : Axios/le navigateur ajoutent le multipart avec sa boundary
  const { data } = await api.post<{ data: AuthUser }>('/api/v1/profile/avatar', form)
  return data.data
}
```

### `src/views/users/EditProfileView.vue`

```vue
<script setup lang="ts">
import { onBeforeUnmount, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { updateProfile, uploadAvatar } from '../../services/users'
import { errorMessage, fieldErrors } from '../../services/api'
import { useAuthStore } from '../../stores/auth'
import UserAvatar from '../../components/UserAvatar.vue'

const MAX_SIZE = 2 * 1024 * 1024
const ALLOWED = ['image/jpeg', 'image/png', 'image/webp']

const auth = useAuthStore()
const router = useRouter()

const form = reactive({
  name: auth.user?.name ?? '',
  username: auth.user?.username ?? '',
  bio: auth.user?.bio ?? '',
})
const errors = ref<Record<string, string>>({})
const globalError = ref('')
const saving = ref(false)
const file = ref<File | null>(null)
const preview = ref<string | null>(null)

function onFile(event: Event) {
  const input = event.target as HTMLInputElement
  const selected = input.files?.[0]
  errors.value = { ...errors.value, avatar: '' }
  if (!selected) return
  if (!ALLOWED.includes(selected.type)) { errors.value.avatar = 'Format accepté : JPG, PNG ou WebP.'; return }
  if (selected.size > MAX_SIZE) { errors.value.avatar = 'Image trop lourde (2 Mo maximum).'; return }
  if (preview.value) URL.revokeObjectURL(preview.value)
  file.value = selected
  preview.value = URL.createObjectURL(selected)
}

async function submit() {
  errors.value = {}
  globalError.value = ''
  saving.value = true
  try {
    let user = await updateProfile({ name: form.name, username: form.username, bio: form.bio || null })
    if (file.value) user = await uploadAvatar(file.value)
    auth.setUser(user)                                   // header et profil immédiatement à jour
    await router.push({ name: 'profile', params: { id: user.id } })
  } catch (e) {
    errors.value = fieldErrors(e)
    if (!Object.keys(errors.value).length) globalError.value = errorMessage(e, 'Enregistrement impossible.')
  } finally {
    saving.value = false
  }
}

onBeforeUnmount(() => { if (preview.value) URL.revokeObjectURL(preview.value) })
</script>

<template>
  <form class="mx-auto max-w-md space-y-4 rounded-xl bg-white p-6 shadow" @submit.prevent="submit">
    <h1 class="text-2xl font-bold">Modifier le profil</h1>

    <div class="flex items-center gap-4">
      <UserAvatar :name="form.name || '?'" :src="preview ?? auth.user?.avatar_url" :size="72" />
      <label class="text-sm font-medium">Avatar
        <input type="file" accept="image/jpeg,image/png,image/webp" class="mt-1 block text-sm" @change="onFile">
      </label>
    </div>
    <p v-if="errors.avatar" role="alert" class="text-sm text-red-600">{{ errors.avatar }}</p>

    <label class="block text-sm font-medium">Nom
      <input v-model.trim="form.name" required maxlength="100" class="mt-1 w-full rounded border p-3">
      <span v-if="errors.name" role="alert" class="text-red-600">{{ errors.name }}</span>
    </label>
    <label class="block text-sm font-medium">Nom d'utilisateur
      <input v-model.trim="form.username" required minlength="3" maxlength="30" class="mt-1 w-full rounded border p-3">
      <span v-if="errors.username" role="alert" class="text-red-600">{{ errors.username }}</span>
    </label>
    <label class="block text-sm font-medium">Bio ({{ form.bio.length }}/300)
      <textarea v-model="form.bio" maxlength="300" rows="3" class="mt-1 w-full rounded border p-3" />
      <span v-if="errors.bio" role="alert" class="text-red-600">{{ errors.bio }}</span>
    </label>

    <p v-if="globalError" role="alert" class="text-sm text-red-600">{{ globalError }}</p>

    <div class="flex gap-2">
      <button type="submit" :disabled="saving" class="flex-1 rounded bg-blue-600 px-4 py-3 font-semibold text-white disabled:opacity-60">
        {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
      </button>
      <button type="button" class="rounded border px-4 py-3" @click="router.back()">Annuler</button>
    </div>
  </form>
</template>
```

### Lien dans `ProfileView.vue` (à la place du commentaire « Ch. 12 »)

```vue
<RouterLink v-if="isOwnProfile" :to="{ name: 'edit-profile' }"
            class="mt-3 inline-block rounded border px-4 py-2 text-sm">Modifier le profil</RouterLink>
```

(importer `RouterLink` depuis `vue-router`.)

## 12.3 Tests

### `tests/Feature/ProfileUpdateTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_own_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/profile', ['name' => 'Nouveau', 'username' => 'Nouveau_01', 'bio' => 'Salut'])
            ->assertOk()->assertJsonPath('data.username', 'nouveau_01');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Nouveau', 'bio' => 'Salut']);
    }

    public function test_guest_cannot_update(): void
    {
        $this->patchJson('/api/v1/profile', ['name' => 'x', 'username' => 'xxx'])->assertUnauthorized();
    }

    public function test_cannot_escalate_role_or_change_another_user(): void
    {
        [$me, $other] = User::factory()->count(2)->create();

        $this->actingAs($me)->patchJson('/api/v1/profile', [
            'name' => 'Moi', 'username' => 'moi123', 'id' => $other->id, 'role' => 'admin',
        ])->assertOk();

        $this->assertSame('user', $me->fresh()->role);
        $this->assertNotSame('Moi', $other->fresh()->name);
    }

    public function test_username_must_be_unique_but_own_username_is_allowed(): void
    {
        $a = User::factory()->create(['username' => 'alice']);
        User::factory()->create(['username' => 'bob']);

        $this->actingAs($a)->patchJson('/api/v1/profile', ['name' => 'A', 'username' => 'bob'])
            ->assertJsonValidationErrors('username');
        $this->actingAs($a)->patchJson('/api/v1/profile', ['name' => 'A', 'username' => 'alice'])->assertOk();
    }

    public function test_bio_too_long_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->patchJson('/api/v1/profile', ['name' => 'A', 'username' => 'abc', 'bio' => str_repeat('a', 301)])
            ->assertJsonValidationErrors('bio');
    }

    public function test_avatar_upload_stores_file_and_replaces_the_old_one(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 200, 200)])->assertOk();
        $first = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($user)->postJson('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('b.png', 200, 200)])->assertOk();
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);
    }

    public function test_non_image_and_oversized_files_are_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf')])
            ->assertJsonValidationErrors('avatar');
        $this->actingAs($user)->postJson('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('big.jpg')->size(3000)])
            ->assertJsonValidationErrors('avatar');
    }
}
```

**Validation :** modifier nom/username/bio, changer l'avatar (aperçu), vérifier le header et le profil mis à jour sans rechargement.

---

# 13. Sécurité, modération et signalements

À réaliser avant toute mise en production.

## 13.1 Signalements et rôles

```bash
php artisan make:model Report -mf
php artisan make:controller Api/V1/ReportController
php artisan make:controller Api/V1/Admin/ReportModerationController
php artisan make:resource ReportResource
```

Migration `create_reports_table` (cible = `post` | `comment` | `user`, sans polymorphisme : on ne touche pas aux types stockés par les notifications) :

```php
Schema::create('reports', function (Blueprint $table) {
    $table->id();
    $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
    $table->string('target_type', 10);             // post | comment | user
    $table->unsignedBigInteger('target_id');
    $table->string('reason', 20);                  // spam | harassment | hate | violence | nudity | other
    $table->string('details', 500)->nullable();
    $table->string('status', 15)->default('pending');   // pending | resolved | dismissed
    $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('handled_at')->nullable();
    $table->timestamps();

    $table->unique(['reporter_id', 'target_type', 'target_id']);   // un signalement par cible et par personne
    $table->index(['status', 'created_at']);
    $table->index(['target_type', 'target_id']);
});
```

`app/Models/Report.php` :

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory;

    public const TARGETS = ['post' => Post::class, 'comment' => Comment::class, 'user' => User::class];
    public const REASONS = ['spam', 'harassment', 'hate', 'violence', 'nudity', 'other'];

    protected $fillable = ['reporter_id', 'target_type', 'target_id', 'reason', 'details', 'status', 'handled_by', 'handled_at'];

    protected function casts(): array { return ['handled_at' => 'datetime']; }

    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reporter_id'); }

    public function target(): ?Model
    {
        $class = self::TARGETS[$this->target_type] ?? null;
        return $class ? $class::find($this->target_id) : null;
    }
}
```

`database/factories/ReportFactory.php` :

```php
public function definition(): array
{
    return [
        'reporter_id' => \App\Models\User::factory(),
        'target_type' => 'post',
        'target_id'   => \App\Models\Post::factory(),
        'reason'      => 'spam',
        'status'      => 'pending',
    ];
}
```

### `app/Http/Resources/ReportResource.php`

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'target_type' => $this->target_type,
            'target_id'   => $this->target_id,
            'reason'      => $this->reason,
            'details'     => $this->details,
            'status'      => $this->status,
            'created_at'  => $this->created_at?->toISOString(),
            // Visible uniquement par la modération
            'reporter'    => $this->when($request->user()?->isModerator(), fn () => new UserBriefResource($this->whenLoaded('reporter'))),
        ];
    }
}
```

### `ReportController`

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    /** GET /reports — chaque utilisateur ne voit QUE ses propres signalements */
    public function index(Request $request)
    {
        return ReportResource::collection(
            Report::where('reporter_id', $request->user()->id)->latest('id')->paginate(20)
        )->additional(['success' => true]);
    }

    /** POST /reports */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', Rule::in(array_keys(Report::TARGETS))],
            'target_id'   => ['required', 'integer', 'min:1'],
            'reason'      => ['required', Rule::in(Report::REASONS)],
            'details'     => ['nullable', 'string', 'max:500'],
        ]);

        $class = Report::TARGETS[$validated['target_type']];
        $target = $class::findOrFail($validated['target_id']);

        $ownerId = $target instanceof User ? $target->id : $target->user_id;
        abort_if($ownerId === $request->user()->id, 422, 'Tu ne peux pas signaler ton propre contenu.');

        $report = Report::firstOrCreate(      // idempotent : pas de doublon
            [
                'reporter_id' => $request->user()->id,
                'target_type' => $validated['target_type'],
                'target_id'   => $validated['target_id'],
            ],
            ['reason' => $validated['reason'], 'details' => $validated['details'] ?? null],
        );

        return (new ReportResource($report))
            ->additional(['success' => true, 'message' => 'Signalement envoyé. Merci.'])
            ->response()->setStatusCode($report->wasRecentlyCreated ? 201 : 200);
    }
}
```

### Modération — `Admin/ReportModerationController`

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReportResource;
use App\Models\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportModerationController extends Controller
{
    /** GET /admin/reports?status=pending */
    public function index(Request $request)
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['pending', 'resolved', 'dismissed'])]])['status'] ?? 'pending';

        return ReportResource::collection(
            Report::with('reporter:id,name,username,avatar_url')->where('status', $status)->latest('id')->paginate(20)
        )->additional(['success' => true]);
    }

    /** PATCH /admin/reports/{report} — traiter un signalement (et supprimer éventuellement le contenu) */
    public function update(Request $request, Report $report): JsonResponse
    {
        $validated = $request->validate([
            'status'         => ['required', Rule::in(['resolved', 'dismissed'])],
            'delete_content' => ['sometimes', 'boolean'],
        ]);

        if (($validated['delete_content'] ?? false) && $report->target_type !== 'user') {
            $report->target()?->delete();
        }

        $report->update([
            'status' => $validated['status'],
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Signalement traité.']);
    }
}
```

### Gate de modération et limiteurs — `app/Providers/AppServiceProvider.php`

```php
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

public function boot(): void
{
    // … ResetPassword::createUrlUsing(...) (§2.2)

    Gate::define('moderate', fn (User $user) => $user->isModerator());

    // 429 lisible, avec les en-têtes Retry-After
    $tooMany = fn (Request $r, array $headers) => response()->json([
        'success' => false,
        'message' => 'Trop de requêtes. Réessaie dans un instant.',
    ], 429, $headers);

    $key = fn (Request $r) => $r->user()?->id ?: $r->ip();

    RateLimiter::for('auth',         fn (Request $r) => Limit::perMinute(20)->by($r->ip())->response($tooMany));
    RateLimiter::for('posting',      fn (Request $r) => Limit::perMinute(10)->by($key($r))->response($tooMany));
    RateLimiter::for('comments',     fn (Request $r) => Limit::perMinute(20)->by($key($r))->response($tooMany));
    RateLimiter::for('interactions', fn (Request $r) => Limit::perMinute(60)->by($key($r))->response($tooMany));   // likes + follow
    RateLimiter::for('search',       fn (Request $r) => Limit::perMinute(30)->by($key($r))->response($tooMany));
    RateLimiter::for('reports',      fn (Request $r) => Limit::perMinute(10)->by($key($r))->response($tooMany));
}
```

## 13.2 Rate limiting appliqué

`config/fortify.php` — limite login, inscription, mot de passe oublié, réinitialisation :

```php
'middleware' => ['web', 'throttle:auth'],
```

Fortify applique déjà son limiteur `login` (5 tentatives/min par email+IP) : conserve-le.

### `routes/api.php` — version finale consolidée

```php
<?php

use App\Http\Controllers\Api\V1\Admin\ReportModerationController;
use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\FollowController;
use App\Http\Controllers\Api\V1\LikeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\UserProfileController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/user', fn (Request $request) => new UserResource($request->user()));

Route::prefix('v1')->group(function () {

    Route::get('/health', fn () => response()->json([
        'success' => true, 'application' => 'TipTok API', 'version' => '1.0.0', 'message' => 'API opérationnelle',
    ]));

    // ── Publiques ────────────────────────────────────────────────────
    Route::get('/posts', [PostController::class, 'index']);
    Route::get('/posts/{post}', [PostController::class, 'show'])->whereNumber('post');
    Route::get('/posts/{post}/comments', [CommentController::class, 'index'])->whereNumber('post');
    Route::get('/users/{user}', [UserProfileController::class, 'show'])->whereNumber('user');
    Route::get('/users/{user}/posts', [PostController::class, 'byUser'])->whereNumber('user');
    Route::get('/search', SearchController::class)->middleware('throttle:search');

    // ── Protégées (un seul groupe auth:sanctum) ──────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Publications
        Route::post('/posts', [PostController::class, 'store'])->middleware('throttle:posting');
        Route::delete('/posts/{post}', [PostController::class, 'destroy'])->whereNumber('post');

        // Likes
        Route::middleware('throttle:interactions')->group(function () {
            Route::post('/posts/{post}/like', [LikeController::class, 'store'])->whereNumber('post');
            Route::delete('/posts/{post}/like', [LikeController::class, 'destroy'])->whereNumber('post');

            // Follow
            Route::post('/users/{user}/follow', [FollowController::class, 'store'])->whereNumber('user');
            Route::delete('/users/{user}/follow', [FollowController::class, 'destroy'])->whereNumber('user');
        });

        // Commentaires
        Route::post('/posts/{post}/comments', [CommentController::class, 'store'])
            ->whereNumber('post')->middleware('throttle:comments');
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])->whereNumber('comment');

        // Profil
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'avatar'])->middleware('throttle:posting');

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->whereUuid('id');

        // Signalements
        Route::get('/reports', [ReportController::class, 'index']);
        Route::post('/reports', [ReportController::class, 'store'])->middleware('throttle:reports');

        // Modération — réservée aux rôles moderator/admin
        Route::middleware('can:moderate')->prefix('admin')->group(function () {
            Route::get('/reports', [ReportModerationController::class, 'index']);
            Route::patch('/reports/{report}', [ReportModerationController::class, 'update'])->whereNumber('report');
        });
    });
});
```

## 13.3 Frontend

### `src/services/reports.ts`

```ts
import { api } from './api'

export type ReportTarget = 'post' | 'comment' | 'user'
export type ReportReason = 'spam' | 'harassment' | 'hate' | 'violence' | 'nudity' | 'other'

export async function sendReport(payload: {
  target_type: ReportTarget; target_id: number; reason: ReportReason; details?: string
}): Promise<void> {
  await api.post('/api/v1/reports', payload)
}
```

### `src/components/ReportButton.vue`

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { sendReport, type ReportReason, type ReportTarget } from '../services/reports'
import { errorMessage } from '../services/api'

const props = defineProps<{ targetType: ReportTarget; targetId: number }>()

const REASONS: { value: ReportReason; label: string }[] = [
  { value: 'spam', label: 'Spam' },
  { value: 'harassment', label: 'Harcèlement' },
  { value: 'hate', label: 'Haine ou discrimination' },
  { value: 'violence', label: 'Violence' },
  { value: 'nudity', label: 'Contenu sexuel' },
  { value: 'other', label: 'Autre' },
]

const open = ref(false)
const reason = ref<ReportReason>('spam')
const details = ref('')
const sending = ref(false)
const done = ref(false)
const error = ref('')

async function submit() {
  sending.value = true
  error.value = ''
  try {
    await sendReport({ target_type: props.targetType, target_id: props.targetId, reason: reason.value, details: details.value || undefined })
    done.value = true
    open.value = false
  } catch (e) {
    error.value = errorMessage(e, 'Signalement impossible.')
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <span class="relative inline-block">
    <p v-if="done" role="status" class="text-xs text-green-700">Signalé</p>
    <button v-else type="button" class="text-xs text-gray-500 hover:underline" :aria-expanded="open" @click="open = !open">
      Signaler
    </button>

    <form v-if="open" class="absolute right-0 z-10 mt-1 w-64 space-y-2 rounded-xl border bg-white p-3 shadow-lg" @submit.prevent="submit">
      <label class="block text-sm font-medium">Raison
        <select v-model="reason" class="mt-1 w-full rounded border p-2">
          <option v-for="r in REASONS" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
      </label>
      <label class="block text-sm font-medium">Détails (facultatif)
        <textarea v-model="details" maxlength="500" rows="2" class="mt-1 w-full rounded border p-2" />
      </label>
      <p v-if="error" role="alert" class="text-xs text-red-600">{{ error }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="rounded border px-3 py-1 text-sm" @click="open = false">Annuler</button>
        <button type="submit" :disabled="sending" class="rounded bg-red-600 px-3 py-1 text-sm text-white disabled:opacity-60">
          {{ sending ? 'Envoi…' : 'Envoyer' }}
        </button>
      </div>
    </form>
  </span>
</template>
```

Intégrations :

- `PostCard.vue`, dans le `<header>`, à la place du commentaire « Ch. 13 » :
  `<ReportButton v-else-if="auth.user" target-type="post" :target-id="post.id" />`
  (le `v-if="isOwner"` du bouton Supprimer devient la première branche du `v-if / v-else-if`).
- `CommentSection.vue`, dans chaque `<li>` : `<ReportButton v-else-if="auth.user" target-type="comment" :target-id="c.id" />`.
- `ProfileView.vue`, hors de son propre profil : `<ReportButton v-if="auth.user && !isOwnProfile" target-type="user" :target-id="profile.id" />`.

### Règles frontend vérifiables

| Règle | Vérification |
|---|---|
| Confirmation avant suppression de post | `ConfirmDialog` (§4.3) |
| Boutons bloqués pendant l'action | `:disabled="loading"` partout |
| Aucun `v-html` sur du contenu utilisateur | `grep -rn "v-html" src/` → **aucun résultat** |
| Erreurs 401/403/404/422/429 lisibles | `errorMessage()` (§1.2) |
| Aucun secret dans le bundle | `grep -rn "SECRET\|APP_KEY" src/ dist/` → vide |

## 13.4 Tests

### `tests/Feature/SecurityTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_use_admin_routes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/admin/reports')->assertForbidden();
    }

    public function test_moderator_can_list_and_resolve_reports_and_delete_content(): void
    {
        $mod = User::factory()->create(['role' => 'moderator']);
        $report = Report::factory()->create();

        $this->actingAs($mod)->getJson('/api/v1/admin/reports')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($mod)->patchJson("/api/v1/admin/reports/{$report->id}", ['status' => 'resolved', 'delete_content' => true])
            ->assertOk();

        $this->assertDatabaseMissing('posts', ['id' => $report->target_id]);
        $this->assertSame('resolved', $report->fresh()->status);
    }

    public function test_user_cannot_delete_other_users_content(): void
    {
        $other = User::factory()->create();
        $post = Post::factory()->create();
        $comment = Comment::factory()->create();

        $this->actingAs($other)->deleteJson("/api/v1/posts/{$post->id}")->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/v1/comments/{$comment->id}")->assertForbidden();
    }

    public function test_report_validation_and_idempotence(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/reports', ['target_type' => 'hack', 'target_id' => 1, 'reason' => 'spam'])
            ->assertJsonValidationErrors('target_type');
        $this->actingAs($user)->postJson('/api/v1/reports', ['target_type' => 'post', 'target_id' => $post->id, 'reason' => 'nope'])
            ->assertJsonValidationErrors('reason');

        $payload = ['target_type' => 'post', 'target_id' => $post->id, 'reason' => 'spam'];
        $this->actingAs($user)->postJson('/api/v1/reports', $payload)->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/reports', $payload)->assertOk();
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_cannot_report_own_content_or_unknown_target(): void
    {
        $post = Post::factory()->create();
        $this->actingAs($post->user)->postJson('/api/v1/reports', ['target_type' => 'post', 'target_id' => $post->id, 'reason' => 'spam'])
            ->assertUnprocessable();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/reports', ['target_type' => 'post', 'target_id' => 99999, 'reason' => 'spam'])
            ->assertNotFound();
    }

    public function test_users_only_see_their_own_reports(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        Report::factory()->create(['reporter_id' => $a->id]);
        Report::factory()->create(['reporter_id' => $b->id]);

        $this->actingAs($a)->getJson('/api/v1/reports')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_post_creation_is_rate_limited_with_a_clear_429(): void
    {
        RateLimiter::clear('posting');
        $user = User::factory()->create();

        foreach (range(1, 10) as $i) {
            $this->actingAs($user)->postJson('/api/v1/posts', ['content' => "p{$i}"])->assertCreated();
        }
        $this->actingAs($user)->postJson('/api/v1/posts', ['content' => 'trop'])
            ->assertStatus(429)->assertJsonPath('success', false);
    }

    public function test_sensitive_data_is_never_returned(): void
    {
        $user = User::factory()->create();
        Post::factory()->create(['user_id' => $user->id]);

        $body = json_encode($this->getJson('/api/v1/posts')->json());
        foreach (['password', 'remember_token', 'avatar_path', '"email"', '"role"'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_invalid_input_is_rejected_on_all_write_endpoints(): void
    {
        $user = User::factory()->create();
        $post = Post::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/posts', ['content' => ['array']])->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/posts/{$post->id}/comments", [])->assertUnprocessable();
        $this->actingAs($user)->patchJson('/api/v1/profile', ['username' => 'bad name!'])->assertUnprocessable();
    }
}
```

> Le test de rate limiting utilise la clé réelle du limiteur : si `RateLimiter::clear('posting')` ne vide pas
> votre cache de test, utilise `Cache::flush()` dans `setUp()`.

**Validation :** `php artisan test --filter=SecurityTest` vert ; `grep -rn "v-html" src/` vide.

---

# 14. Accessibilité, UX et responsive design

## 14.1 Règles à appliquer à **chaque** vue

| Règle | Implémentation |
|---|---|
| Labels explicites | `<label for>` ou `<label>` englobant ; `sr-only` si le label est masqué visuellement |
| Type de bouton | `type="button"` ou `type="submit"` systématique (jamais le défaut) |
| État désactivé pendant les requêtes | `:disabled="loading"` + libellé « … en cours » |
| Erreurs / succès annoncés | `role="alert"` (erreur), `role="status"` (succès, chargement) |
| Navigation clavier | Éléments interactifs natifs (`button`, `a`), focus visible, `Échap` ferme les dialogues |
| Contrastes | Texte `gray-600` minimum sur fond clair ; vérifier avec les DevTools (ratio ≥ 4.5:1) |
| Images | `alt` descriptif (`Avatar de {{ name }}`) |
| Mobile + desktop | Mobile-first, `max-w-2xl`, zones tactiles ≥ 40 px |
| États vides / chargement | `LoadingState`, messages « Aucun… » |
| Pas de perte de saisie | Les champs ne sont jamais vidés sur erreur (voir `PostComposer`, `LoginView`) |

## 14.2 Composants réutilisables

Déjà fournis : `AppHeader` (§10.3), `PostCard` (§4–9), `LoadingState` / `ErrorMessage` (§3.3), `ConfirmDialog` (§4.3).

### `src/components/AppNav.vue` — barre de navigation mobile (bas d'écran)

```vue
<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useNotificationsStore } from '../stores/notifications'

const auth = useAuthStore()
const notifications = useNotificationsStore()
</script>

<template>
  <nav class="fixed inset-x-0 bottom-0 z-40 flex border-t bg-white md:hidden" aria-label="Navigation mobile"
       style="padding-bottom: env(safe-area-inset-bottom)">
    <RouterLink to="/" class="flex-1 py-3 text-center" active-class="font-bold text-blue-600">Accueil</RouterLink>
    <RouterLink to="/search" class="flex-1 py-3 text-center" active-class="font-bold text-blue-600">Recherche</RouterLink>
    <template v-if="auth.user">
      <RouterLink to="/notifications" class="relative flex-1 py-3 text-center" active-class="font-bold text-blue-600">
        Notifs
        <span v-if="notifications.unread > 0"
              class="absolute right-4 top-1 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ notifications.unread }}</span>
      </RouterLink>
      <RouterLink :to="{ name: 'profile', params: { id: auth.user.id } }" class="flex-1 py-3 text-center"
                  active-class="font-bold text-blue-600">Profil</RouterLink>
    </template>
    <RouterLink v-else to="/login" class="flex-1 py-3 text-center">Connexion</RouterLink>
  </nav>
</template>
```

Dans `App.vue`, ajouter `<AppNav />` après `</main>` et `pb-20 md:pb-6` sur `<main>` pour ne pas masquer le contenu. Dans `AppHeader.vue`, masquer les liens secondaires sur mobile avec `hidden md:inline`.

### `ConfirmDialog.vue` — gestion du focus (amélioration)

```vue
<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

const props = defineProps<{ open: boolean; title: string; message: string; confirmLabel?: string; loading?: boolean }>()
defineEmits<{ confirm: []; cancel: [] }>()

const cancelBtn = ref<HTMLButtonElement | null>(null)
let previouslyFocused: HTMLElement | null = null

watch(() => props.open, async (open) => {
  if (open) {
    previouslyFocused = document.activeElement as HTMLElement | null
    await nextTick()
    cancelBtn.value?.focus()            // focus sur l'action la moins destructive
  } else {
    previouslyFocused?.focus()          // retour du focus au déclencheur
  }
})
</script>

<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
       role="dialog" aria-modal="true" :aria-label="title" @keydown.esc="$emit('cancel')">
    <div class="w-full max-w-sm space-y-4 rounded-xl bg-white p-6 shadow-lg">
      <h2 class="text-lg font-bold">{{ title }}</h2>
      <p>{{ message }}</p>
      <div class="flex justify-end gap-2">
        <button ref="cancelBtn" type="button" class="rounded border px-4 py-2" @click="$emit('cancel')">Annuler</button>
        <button type="button" :disabled="loading" class="rounded bg-red-600 px-4 py-2 text-white disabled:opacity-60"
                @click="$emit('confirm')">{{ confirmLabel ?? 'Confirmer' }}</button>
      </div>
    </div>
  </div>
</template>
```

### `src/style.css`

```css
@import "tailwindcss";

/* Focus clavier visible partout */
:where(a, button, input, select, textarea, [tabindex]):focus-visible {
  outline: 2px solid #2563eb;
  outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
  * { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
}
```

### `src/views/NotFoundView.vue`

```vue
<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <section class="py-16 text-center">
    <h1 class="text-3xl font-bold">Page introuvable</h1>
    <RouterLink to="/" class="mt-4 inline-block text-blue-600 underline">Retour à l'accueil</RouterLink>
  </section>
</template>
```

## 14.3 Protocole de test manuel

1. **Mobile** : DevTools → viewport 375 × 667 ; aucune barre de défilement horizontale, navigation basse visible.
2. **Clavier** : parcourir login → fil → like → commentaire → suppression avec `Tab` / `Entrée` / `Échap` uniquement.
3. **Formulaires** : chaque champ a un label ; une erreur serveur ne vide pas la saisie.
4. **États** : couper l'API → messages d'erreur + bouton « Réessayer » ; fil vide → message vide.
5. **Console navigateur** : aucune erreur ni avertissement Vue.
6. **Lecteur d'écran** (NVDA/VoiceOver) : erreurs et compteurs annoncés.

---

# 15. Tests automatisés Laravel

## 15.1 Organisation

| Série | Fichier | Chapitre |
|---|---|---|
| 1. Authentification | `AuthTest.php` | 2 |
| 2. Profils | `ProfileShowTest.php`, `ProfileUpdateTest.php` | 3, 12 |
| 3. Publications | `PostTest.php` | 4 |
| 4. Likes | `LikeTest.php` | 5 |
| 5. Commentaires | `CommentTest.php` | 6 |
| 6. Follow/Unfollow | `FollowTest.php` | 7 |
| 7. Fil personnalisé | `FeedTest.php` | 8 |
| 8. Notifications | `NotificationTest.php` | 10 |
| 9. Recherche | `SearchTest.php` | 11 |
| 10. Sécurité et autorisations | `SecurityTest.php` | 13 |
| 11. Diffusion Reverb | `RealtimeTest.php` | 9 |
| Socle | `HealthTest.php` | 1 |

## 15.2 Commandes

```bash
php artisan test                                  # tout
php artisan test --filter=LikeTest                # une classe
php artisan test --filter=test_user_can_comment   # une méthode
php artisan test --parallel                       # plus rapide (paratest requis)
php artisan test --coverage --min=70              # couverture (Xdebug/PCOV requis)
```

## 15.3 Conventions

- `use RefreshDatabase;` dans chaque test touchant la base (SQLite en mémoire pour la vitesse : `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` dans `phpunit.xml`).
- Toujours des **factories** (`User`, `Post`, `Comment`, `Report`) adaptées aux colonnes réelles.
- Un test = un comportement ; nom explicite (`test_only_the_author_can_delete`).
- Tester le chemin **heureux**, le **refus** (401/403/404/422/429) et l'**idempotence**.
- Les événements sont testés avec `Event::fake()` ; les fichiers avec `Storage::fake('public')`.

---

# 16. Tests frontend (Vitest)

## 16.1 Installation et configuration

```bash
cd ~/sites/app-tiptok/frontend
npm install -D vitest @vue/test-utils jsdom
```

`vite.config.ts` (conserve les plugins Vue et Tailwind existants) :

```ts
/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  test: {
    environment: 'jsdom',
    globals: true,
    include: ['src/**/*.{spec,test}.ts'],
  },
})
```

`package.json` :

```json
{
  "scripts": {
    "test": "vitest",
    "test:run": "vitest run"
  }
}
```

## 16.2 Exemples de tests de services (mock Axios)

`src/services/__tests__/posts.spec.ts` :

```ts
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../api', () => ({
  api: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
  errorMessage: (e: unknown, fallback: string) => fallback,
}))

import { api } from '../api'
import { createPost, fetchPosts, likePost } from '../posts'

describe('services/posts', () => {
  beforeEach(() => vi.clearAllMocks())

  it('fetchPosts transmet la page et le type de fil', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [], meta: { last_page: 1 } } })
    await fetchPosts(2, 'following')
    expect(api.get).toHaveBeenCalledWith('/api/v1/posts', { params: { page: 2, feed: 'following' } })
  })

  it('createPost renvoie la publication créée', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { data: { id: 9, content: 'x' } } })
    await expect(createPost('x')).resolves.toMatchObject({ id: 9 })
  })

  it('likePost propage les erreurs réseau', async () => {
    vi.mocked(api.post).mockRejectedValue(new Error('network'))
    await expect(likePost(1)).rejects.toThrow('network')
  })
})
```

`src/components/__tests__/CommentSection.spec.ts` (anti-doublon temps réel) :

```ts
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CommentSection from '../CommentSection.vue'
import * as service from '../../services/comments'

const comment = {
  id: 1, post_id: 5, user_id: 2, content: 'Salut', created_at: '2026-01-01T10:00:00Z',
  user: { id: 2, name: 'Bob', username: 'bob', avatar_url: null },
}

describe('CommentSection', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('charge et affiche les commentaires', async () => {
    vi.spyOn(service, 'fetchComments').mockResolvedValue({
      success: true, data: [comment], links: {} as never, meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
    })
    const w = mount(CommentSection, { props: { postId: 5 } })
    await flushPromises()
    expect(w.text()).toContain('Salut')
  })

  it("n'ajoute pas deux fois le même commentaire reçu en temps réel", async () => {
    vi.spyOn(service, 'fetchComments').mockResolvedValue({
      success: true, data: [comment], links: {} as never, meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
    })
    const w = mount(CommentSection, { props: { postId: 5 } })
    await flushPromises()
    ;(w.vm as unknown as { receive: (c: typeof comment) => void }).receive(comment)
    await flushPromises()
    expect(w.findAll('li')).toHaveLength(1)
  })

  it("affiche l'erreur de chargement sans planter", async () => {
    vi.spyOn(service, 'fetchComments').mockRejectedValue(new Error('x'))
    const w = mount(CommentSection, { props: { postId: 5 } })
    await flushPromises()
    expect(w.find('[role="alert"]').exists()).toBe(true)
  })
})
```

## 16.3 Ce qu'il faut couvrir (par chapitre)

| Chapitre | Cas frontend |
|---|---|
| Auth | Formulaire invalide bloqué, erreur serveur affichée, utilisateur dans Pinia après connexion, session récupérée au rechargement |
| Posts | Compteur 500, bouton désactivé pendant l'envoi, ajout/suppression dans le fil |
| Likes | Compteur et état issus de la réponse API, erreur isolée à la carte |
| Commentaires | Ajout, suppression auteur uniquement, pas de doublon |
| Follow | Bouton absent sur son profil, compteurs issus de l'API |
| Fil | Changement d'onglet relance le chargement ; requête obsolète ignorée |
| Temps réel | `receive()` sans doublon, nettoyage des listeners |
| Notifications | Dédoublonnage par `notification_id`, compteur exact |
| Recherche | Debounce, annulation de la requête précédente |
| Profil | Validation fichier (type/taille), aperçu, erreurs serveur |

## 16.4 Commandes

```bash
npm run test:run
npm run build        # vérifie aussi le typage (vue-tsc si configuré)
```

---

# 17. CI, qualité et déploiement

## 17.1 Vérifications avant chaque livraison

```bash
# Backend
php artisan test
php artisan route:list
php artisan config:clear
vendor/bin/pint --test          # style (Laravel Pint)

# Frontend
npm run test:run
npm run build
```

## 17.2 Intégration continue — GitHub Actions

`.github/workflows/backend.yml` :

```yaml
name: Backend
on:
  push: { paths: ['api-tiptok/**'] }
  pull_request: { paths: ['api-tiptok/**'] }

jobs:
  test:
    runs-on: ubuntu-latest
    defaults: { run: { working-directory: api-tiptok } }
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: mbstring, pdo_sqlite, coverage: none }
      - run: cp .env.example .env
      - run: composer install --no-interaction --prefer-dist
      - run: php artisan key:generate
      - run: vendor/bin/pint --test
      - run: php artisan test
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'
```

`.github/workflows/frontend.yml` :

```yaml
name: Frontend
on:
  push: { paths: ['frontend/**'] }
  pull_request: { paths: ['frontend/**'] }

jobs:
  test:
    runs-on: ubuntu-latest
    defaults: { run: { working-directory: frontend } }
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 22, cache: npm, cache-dependency-path: frontend/package-lock.json }
      - run: npm ci
      - run: npm run test:run
      - run: npm run build
```

> Ajuste `php-version` et `node-version` aux versions réellement requises par ton `composer.json` / `package.json`.

## 17.3 Production

| Exigence | Mise en œuvre |
|---|---|
| HTTPS obligatoire | Certificat (Let's Encrypt) ; redirection 80 → 443 |
| `APP_DEBUG=false`, `APP_ENV=production` | `.env` du serveur uniquement |
| Secrets hors Git | `.env` ignoré ; variables serveur / gestionnaire de secrets |
| CORS limité | `FRONTEND_URL=https://app.exemple.com` (domaine réel) |
| Cookies sécurisés | `SESSION_SECURE_COOKIE=true`, `SESSION_DOMAIN=.exemple.com`, `SANCTUM_STATEFUL_DOMAINS=app.exemple.com` |
| Migrations contrôlées | `php artisan migrate --force` dans un script de déploiement, après sauvegarde |
| Worker de queue | Supervisor/systemd (ci-dessous) |
| Reverb persistant | Service + proxy TLS (ci-dessous) |
| Sauvegardes | Dump MySQL quotidien + copie du disque des avatars |
| Logs/alertes | `LOG_CHANNEL`, supervision (Sentry, Better Stack…) |
| Limites d'upload | `upload_max_filesize`, `post_max_size`, `client_max_body_size` ≥ 2 Mo |
| Conformité | Politique de conservation + procédure de suppression de compte |

### Commandes de déploiement

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan queue:restart
```

### `/etc/supervisor/conf.d/tiptok.conf`

```ini
[program:tiptok-queue]
command=php /var/www/api-tiptok/artisan queue:work --sleep=1 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
process_name=%(program_name)s_%(process_num)02d
stopwaitsecs=3600

[program:tiptok-reverb]
command=php /var/www/api-tiptok/artisan reverb:start --host=127.0.0.1 --port=8080
autostart=true
autorestart=true
user=www-data
stopwaitsecs=10
minfds=10000
```

### Nginx — proxy WebSocket TLS pour Reverb

```nginx
server {
    listen 443 ssl http2;
    server_name ws.exemple.com;

    ssl_certificate     /etc/letsencrypt/live/ws.exemple.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/ws.exemple.com/privkey.pem;

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Scheme $scheme;
        proxy_read_timeout 120s;
        proxy_pass http://127.0.0.1:8080;
    }
}
```

Frontend production : `VITE_REVERB_HOST=ws.exemple.com`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`.

> **Ne pas déployer** avant d'avoir testé : canaux privés, autorisations, queue (`queue:work` actif) et reconnexion WebSocket.

---

# 18. Checklist finale

## Socle
- [ ] API de santé, Axios et CORS vérifiés
- [ ] Tests Laravel et build Vue passent

## Authentification
- [ ] Inscription et connexion
- [ ] Session Sanctum persistante
- [ ] Déconnexion
- [ ] Mot de passe oublié / réinitialisation
- [ ] Vérification email si activée
- [ ] Routes privées protégées

## Profils
- [ ] Profil public
- [ ] Modifier nom, username, bio
- [ ] Avatar
- [ ] Compteurs abonnés / abonnements
- [ ] Publications paginées

## Publications et interaction
- [ ] Créer / lister / consulter / supprimer ses publications
- [ ] Like / Unlike
- [ ] Commentaires, suppression et pagination
- [ ] Follow / Unfollow
- [ ] Fil global
- [ ] Fil des abonnements

## Temps réel
- [ ] Reverb démarre
- [ ] Queue fonctionnelle
- [ ] Like diffusé sur `post.{id}`
- [ ] Commentaire diffusé sur `post.{id}`
- [ ] Canal privé `users.{id}` autorisé
- [ ] Notification Follow instantanée
- [ ] Aucun doublon après reconnexion
- [ ] Nettoyage des listeners

## Compléments
- [ ] Notifications persistantes et compteur non lu
- [ ] Recherche
- [ ] Signalements et modération
- [ ] Rate limiting
- [ ] Tests automatisés backend et frontend
- [ ] Responsive, accessibilité et états d'erreur
- [ ] Configuration production et sauvegardes

## Matrice fonctionnalité → livrables → commande de validation

| # | Fonctionnalité | Migrations | Backend | Frontend | Validation |
|---|---|---|---|---|---|
| 1 | Socle | — | `routes/api.php`, CORS, `bootstrap/app.php` | `api.ts`, `health.ts`, router | `php artisan test --filter=HealthTest` |
| 2 | Auth | `add_profile_fields_to_users` | `CreateNewUser`, Resources | `auth.ts`, store, 5 vues | `--filter=AuthTest` |
| 3 | Profils | — | `User`, `UserProfileController` | `users.ts`, `ProfileView`, `UserAvatar` | `--filter=ProfileShowTest` |
| 4 | Posts | `create_posts_table` | `Post`, `PostController`, `PostPolicy` | `posts.ts`, `FeedView`, `PostCard`, `PostComposer` | `--filter=PostTest` |
| 5 | Likes | `create_likes_table` | `Like`, `LikeController` | like dans `PostCard` | `--filter=LikeTest` |
| 6 | Commentaires | `create_comments_table` | `Comment`, `CommentController` | `comments.ts`, `CommentSection` | `--filter=CommentTest` |
| 7 | Follow | `create_follows_table` | `FollowController` | `follows.ts`, bouton profil | `--filter=FollowTest` |
| 8 | Fils | — | `PostController@index` | onglets `FeedView` | `--filter=FeedTest` |
| 9 | Reverb | queue/jobs | 3 Events, `channels.php` | `echo.ts`, `useRealtimePost` | `--filter=RealtimeTest` |
| 10 | Notifications | `notifications` | `TipTokNotification`, `NotificationController` | store, vue, header | `--filter=NotificationTest` |
| 11 | Recherche | — | `SearchController` | `search.ts`, `SearchView` | `--filter=SearchTest` |
| 12 | Profil/avatar | (`avatar_path`) | `ProfileController` | `EditProfileView` | `--filter=ProfileUpdateTest` |
| 13 | Sécurité | `create_reports_table` | `Report*`, limiters, Gate | `ReportButton` | `--filter=SecurityTest` |
| 14–17 | UX, tests, CI, prod | — | workflows, Supervisor | Vitest | `npm run test:run && npm run build` |

---

# 19. Méthode de travail et ordre conseillé

## 19.1 Procédure à chaque étape

1. Indiquer le **numéro et la fonctionnalité** (ex. « Chapitre 9 — événements Reverb »).
2. **Comparer** les fichiers existants avec ce document avant de les remplacer.
3. Implémenter **uniquement** la fonctionnalité en cours.
4. Exécuter les **commandes exactes** (migrations, tests).
5. Ajouter les **tests backend et frontend** du chapitre.
6. Lancer `php artisan test` et `npm run test:run` ; corriger.
7. **Valider** avant de passer à la suite.

## 19.2 Ordre de réalisation conseillé à partir de l'état actuel

Le projet a déjà publications, Likes, commentaires, abonnements, profils et un début de Reverb. Ordre recommandé :

| Étape | Travail | Chapitres |
|---|---|---|
| 1 | Finir et tester les événements Reverb existants | 9.2, 9.6 |
| 2 | Écouter les événements dans Vue (Likes, commentaires, Follow) | 9.3, 9.4 |
| 3 | API et vue des notifications persistantes | 10 |
| 4 | Modifier le profil et gérer l'avatar | 12 |
| 5 | Recherche | 11 |
| 6 | Sécurité, signalements, modération, rate limiting | 13 |
| 7 | Tests automatisés complets | 15, 16 |
| 8 | Optimisation UX / accessibilité | 14 |
| 9 | Déploiement | 17 |

## 19.3 Pièges fréquents

| Symptôme | Cause probable | Correction |
|---|---|---|
| `419` sur login/inscription | Cookie CSRF absent | Appeler `/sanctum/csrf-cookie` avant ; `withCredentials` + `withXSRFToken` ; `SANCTUM_STATEFUL_DOMAINS` |
| `401` sur `/api/user` après login | Domaine/port non déclaré stateful, ou CORS sans credentials | `supports_credentials=true`, origine exacte, `statefulApi()` |
| Événement jamais reçu | `queue:work` non lancé | Lancer le worker (ou `ShouldBroadcastNow` pour un test) |
| `403` sur `/api/broadcasting/auth` | Canal mal autorisé ou session absente | Vérifier `channels.php`, cookies, `authorizer` Echo |
| `403` sur canal privé de test | `BROADCAST_CONNECTION=null` en test | Configurer `reverb` + clés factices dans `phpunit.xml` |
| Doublons de commentaires | Écoute multiple ou pas de déduplication | `echo.leave` au démontage ; filtrer par `id` |
| Compteur de likes faux | Compte calculé localement | Toujours utiliser `likes_count` renvoyé par l'API |
| `Class "App\Models\Like" not found` | Modèle `Like` manquant | Créer `Like` (§4.1) |
| Avatar 404 | Lien symbolique absent | `php artisan storage:link` |
| `429` inattendu en développement | Limiteur trop strict | Ajuster les limites dans `AppServiceProvider` |

---

*Fin du document. Chaque bloc de code est un patron à intégrer et à adapter à tes contrôleurs, migrations, types et conventions existants : après chaque intégration, exécute les tests plutôt que de remplacer aveuglément un fichier entier.*
