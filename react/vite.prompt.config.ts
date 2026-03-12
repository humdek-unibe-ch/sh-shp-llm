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
      entry: resolve(__dirname, 'src/LlmPromptField.tsx'),
      name: 'LlmPromptField',
      fileName: () => 'llm-prompt-field.umd.js',
      formats: ['umd'],
    },
    rollupOptions: {
      output: {
        name: 'LlmPromptField',
        format: 'umd',
        inlineDynamicImports: true,
        entryFileNames: 'llm-prompt-field.umd.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name === 'style.css') {
            return 'llm-prompt-field.css';
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
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
});

