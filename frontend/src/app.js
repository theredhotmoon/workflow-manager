import { createApp } from 'vue';
import App from './App.vue';
import api from './axios.js';
import { useToast } from './composables/useToast.js';
import router, { currentUser } from './router';
import { installSessionExpiry } from './sessionExpiry.js';
import './css/app.css';

const app = createApp(App);
app.use(router);
// A 401 on any API call while logged in (session expired, account deactivated) goes to login.
installSessionExpiry(api, { currentUser, router, showToast: useToast().showToast });
app.mount('#app');
