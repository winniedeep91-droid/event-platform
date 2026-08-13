// Build configuration for the EventOS WordPress admin application.
// Output goes straight into the plugin so `wp-admin` can enqueue it:
//   bun run build:wp-admin
import { defineConfig, type Plugin } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "node:path";
import { readFileSync, writeFileSync } from "node:fs";
import { transform } from "lightningcss";

// Vite's own `css.lightningcss.targets` option does not reliably reach the
// minifier in this build (verified: the emitted CSS still used the Media
// Queries Level 4 range syntax, e.g. `@media (width <= 900px)`, even with
// targets set). That syntax isn't supported before Safari 16.4, which would
// silently break every responsive breakpoint (the admin sidebar never
// collapsing, for example) on those browsers. This plugin re-runs the
// already-minified CSS output through Lightning CSS directly — confirmed by
// hand to correctly keep the traditional `max-width`/`min-width` syntax —
// as a final pass once Vite has finished writing it.
function downlevelCssMediaSyntax(): Plugin {
  return {
    name: "downlevel-css-media-syntax",
    apply: "build",
    closeBundle() {
      const cssPath = resolve(
        import.meta.dirname,
        "wordpress-plugin/eventos/assets/admin/eventos-admin.css",
      );
      const source = readFileSync(cssPath);
      const { code } = transform({
        filename: "eventos-admin.css",
        code: source,
        minify: true,
        targets: { safari: (15 << 16) | (0 << 8) },
      });
      writeFileSync(cssPath, code);
    },
  };
}

export default defineConfig({
  plugins: [react(), downlevelCssMediaSyntax()],
  resolve: {
    alias: { "@": resolve(import.meta.dirname, "src") },
  },
  css: {
    transformer: "lightningcss",
    lightningcss: {
      // Without an explicit target, Lightning CSS assumes every browser
      // supports the latest CSS syntax and rewrites `@media (max-width:
      // Npx)` into the Media Queries Level 4 range syntax
      // `@media (width <= Npx)`. That isn't supported before Safari 16.4,
      // which would silently break every responsive breakpoint (e.g. the
      // admin sidebar never collapsing) on those browsers. Targeting
      // Safari 15 forces it to keep emitting the traditional syntax.
      targets: { safari: (15 << 16) | (0 << 8) },
    },
  },
  build: {
    outDir: "wordpress-plugin/eventos/assets/admin",
    emptyOutDir: true,
    cssCodeSplit: false,
    cssMinify: "lightningcss",
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
