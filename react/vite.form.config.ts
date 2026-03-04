/**
 * Vite Configuration for LLM Form React Component
 * ================================================
 * 
 * Builds the LLM Form component as a UMD bundle for SelfHelp CMS.
 * Handles the LLM result panel UI for llmFormRecord and llmFormLog styles.
 */
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],

  define: {
    'process.env': {},
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
  },

  build: {
    lib: {
      entry: resolve(__dirname, 'src/LlmForm.tsx'),
      name: 'LlmForm',
      fileName: (format) => `llm-form.${format}.js`,
      formats: ['umd'],
    },

    rollupOptions: {
      output: {
        name: 'LlmForm',
        format: 'umd',
        inlineDynamicImports: true,
        entryFileNames: 'llm-form.umd.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name === 'style.css') {
            return 'llm-form.css';
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
