import { createApp } from 'vue'
import '@fontsource-variable/inter'
import '@fontsource-variable/bricolage-grotesque'
import App from './App.vue'
import router from './router.js'
import './style.css'

createApp(App).use(router).mount('#app')
