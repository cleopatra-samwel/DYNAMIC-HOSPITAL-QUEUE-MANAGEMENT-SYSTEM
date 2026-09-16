import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
  ],
  server: {
    // Bind to all interfaces (not just loopback) so a phone on the same
    // WiFi network can reach the dev server via the machine's LAN IP —
    // needed for scanning the check-in/tracking QR codes from a real device.
    host: true,
  },
})