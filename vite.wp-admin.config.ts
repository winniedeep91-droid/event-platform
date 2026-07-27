// Build configuration for the EventOS WordPress admin application.
// Output goes straight into the plugin so `wp-admin` can enqueue it:
//   bun run build:wp-admin
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "node:path";

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { "@": resolve(import.meta.dirname, "src") },
  },
  build: {
    outDir: "wordpress-plugin/eventos/assets/admin",
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: resolve(import.meta.dirname, "src/wp-admin/main.tsx"),
      output: {
        entryFileNames: "eventos-admin.js",
        chunkFileNames: "eventos-admin-[name].js",
        assetFileNames: "eventos-admin.[ext]",
        format: "iife",
      },
    },
  },
});
