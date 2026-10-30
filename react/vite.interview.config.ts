import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  define: {
    'process.env': {},
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
  },
  build: {
    lib: {
      entry: path.resolve(__dirname, 'src/LlmFormInterview.tsx'),
      name: 'LlmFormInterview',
      fileName: () => 'llm-form-interview.umd.js',
      formats: ['umd']
    },
    rollupOptions: {
      output: {
        name: 'LlmFormInterview',
        format: 'umd',
        inlineDynamicImports: true,
        entryFileNames: 'llm-form-interview.umd.js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name === 'style.css') {
            return 'llm-form-interview.css';
          }
          return assetInfo.name || 'assets/[name][extname]';
        }
      }
    },
    outDir: '../js/ext',
    emptyOutDir: false,
    sourcemap: false,
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: false,
        drop_debugger: true
      },
      format: {
        comments: false
      }
    },
    cssCodeSplit: false
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src')
    }
  }
});
