import './bootstrap';
import { initializeApp } from "firebase/app";
import { getAuth, GoogleAuthProvider, signInWithPopup } from "firebase/auth";

const firebaseConfig = {
  apiKey: "AIzaSyAD44mV8qj3zq1xS5Uun2dn_ODXt5HhmOc",
  authDomain: "liveapp-8493c.firebaseapp.com",
  projectId: "liveapp-8493c",
  storageBucket: "liveapp-8493c.firebasestorage.app",
  messagingSenderId: "106498003101",
  appId: "1:106498003101:web:a636200d27b749efd70474",
  measurementId: "G-PGJRFVJ2KV"
};

const app = initializeApp(firebaseConfig);
const auth = getAuth(app);
const provider = new GoogleAuthProvider();

const btn = document.getElementById('googleLoginBtn');

if (btn) {
  btn.addEventListener('click', async () => {
    try {
      const result = await signInWithPopup(auth, provider);
      const idToken = await result.user.getIdToken(true);

      const resp = await fetch('/auth/firebase/login', {
        method: 'POST',
        headers: {
          'Content-Type':'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({ idToken })
      });

      if (!resp.ok) {
        const text = await resp.text();
        console.error('Server error:', resp.status, text);
        alert('Server login failed: ' + resp.status);
        return;
      }

      const data = await resp.json();
      if (data.ok) location.href = data.redirect;
    } catch (e) {
      console.error('Firebase client error:', e.code, e.message, e);
      alert((e.code || 'error') + ': ' + (e.message || 'Login failed'));
    }
  });
}
