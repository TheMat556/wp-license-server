import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./src/test-setup.ts"],
  },
  build: {
    outDir: "dist",
    emptyOutDir: true,
    cssCodeSplit: false,
    rollupOptions: {
      input: { admin: "src/admin/main.tsx" },
      output: {
        entryFileNames: "license-server-admin-app.js",
        chunkFileNames: "license-server-admin-[hash].js",
        assetFileNames: "license-server-admin-app[extname]",
      },
    },
  },
});
