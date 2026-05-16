import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'
import { useAuth } from './stores/auth'

async function bootstrap() {
  const { isAuthenticated, checkAuth } = useAuth()
  if (isAuthenticated.value) {
    await checkAuth()
  }
  createApp(App).use(router).mount('#app')
}

bootstrap()
