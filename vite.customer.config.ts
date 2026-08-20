// Build configuration for EventOS's customer-facing assets — currently just
// the ticket QR renderer that runs on WooCommerce's order-received / My
// Account → View Order pages (see Ticket_Display). Deliberately separate
// from vite.wp-admin.config.ts: this is a tiny vanilla script for a
// WooCommerce-rendered page, not part of the admin React app, and must not
// pull that app's dependencies (or its `emptyOutDir`) into a customer page.
//   bun run build:customer
import { defineConfig } from "vite";
import { resolve } from "node:path";

export default defineConfig({
  // No static assets belong in this bundle — without this, Vite copies the
  // project's top-level public/ directory (the unrelated legacy app's
  // favicon.ico) into assets/customer/ on every build.
  publicDir: false,
  build: {
    outDir: "wordpress-plugin/eventos/assets/customer",
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(import.meta.dirname, "src/customer/ticket-qr.ts"),
      output: {
        entryFileNames: "ticket-qr.js",
        format: "iife",
      },
    },
  },
});
