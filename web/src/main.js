import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'

// Mock: pre-authenticate for easier mockup navigation
// Remove this in production
if (!sessionStorage.getItem('mock_auth')) {
  sessionStorage.setItem('mock_auth', 'true')
  sessionStorage.setItem('mock_admin', 'false')
}

createApp(App).use(router).mount('#app')
