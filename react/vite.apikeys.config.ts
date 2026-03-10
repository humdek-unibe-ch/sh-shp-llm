/**
 * Vite Configuration for LLM API Keys Manager
 *
 * Builds a standalone UMD bundle used in CMS for the
 * multi-server API keys editor.
 */
import { defineConfig } from 'vite';
import { resolve } from 'path';

export default defineConfig({
  define: {
    'process.env': {},
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
  },
  build: {
    lib: {
      entry: resolve(__dirname, 'src/LlmApiKeys.ts'),
      name: 'LlmApiKeys',
      fileName: () => 'llm-apikeys.umd.js',
      formats: ['umd'],
    },
    rollupOptions: {
      output: {
        name: 'LlmApiKeys',
        format: 'umd',
        inlineDynamicImports: true,
        entryFileNames: 'llm-apikeys.umd.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name === 'style.css') {
            return 'llm-apikeys.css';
          }
          return assetInfo.name || 'assets/[name][extname]';
        },
      },
    },
    outDir: '../js/ext',
    emptyOutDir: false,
    sourcemap: false,
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: false,
        drop_debugger: true,
      },
      format: {
        comments: false,
      },
    },
    cssCodeSplit: false,
  },
  css: {
    modules: {
      localsConvention: 'camelCase',
    },
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
});
