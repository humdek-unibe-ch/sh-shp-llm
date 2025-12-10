/**
 * Vite Configuration for LLM Chat React Component
 * ================================================
 * 
 * Builds the React chat component as a UMD (Universal Module Definition) bundle
 * that can be loaded directly in SelfHelp CMS pages without requiring a full
 * React application setup.
 * 
 * The bundle includes React and ReactDOM, making it self-contained.
 * 
 * @see https://vitejs.dev/config/
 */
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
  
  /**
   * Global definitions to ensure compatibility with libraries that expect
   * Node.js-like environment variables
   */
  define: {
    'process.env': {},
    'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
  },
  
  /**
   * Build configuration for UMD library output
   */
  build: {
    /**
     * Library mode configuration
     * Outputs a UMD bundle that exposes LlmChat globally
     */
    lib: {
      entry: resolve(__dirname, 'src/LlmChat.tsx'),
      name: 'LlmChat',
      fileName: (format) => `llm-chat.${format}.js`,
      formats: ['umd'],
    },
    
    /**
     * Rollup-specific options for the UMD bundle
     */
    rollupOptions: {
      /**
       * We bundle React and ReactDOM inside the UMD file
       * This makes the bundle self-contained and easier to deploy
       */
      output: {
        /**
         * UMD global name for the library
         */
        name: 'LlmChat',
        
        /**
         * Ensure proper module format
         */
        format: 'umd',
        
        /**
         * Generate a single file without code splitting
         */
        inlineDynamicImports: true,
        
        /**
         * Entry file naming
         */
        entryFileNames: 'llm-chat.umd.js',
        
        /**
         * Asset file naming (CSS will be extracted here)
         */
        assetFileNames: (assetInfo) => {
          if (assetInfo.name === 'style.css') {
            return 'llm-chat.css';
          }
          return assetInfo.name || 'assets/[name][extname]';
        },
      },
    },
    
    /**
     * Output directory - builds to js/ext/ for SelfHelp integration
     */
    outDir: '../js/ext',
    
    /**
     * Don't clear the output directory (preserve other files)
     */
    emptyOutDir: false,
    
    /**
     * Generate source maps for debugging
     */
    sourcemap: false,
    
    /**
     * Minify the output for production
     */
    minify: 'terser',
    
    /**
     * Terser options for minification
     */
    terserOptions: {
      compress: {
        drop_console: false, // Keep console.error for debugging
        drop_debugger: true,
      },
      format: {
        comments: false,
      },
    },
    
    /**
     * CSS code splitting - extract CSS to a separate file
     */
    cssCodeSplit: false,
  },
  
  /**
   * Development server configuration
   * Useful for local development and testing
   */
  server: {
    port: 3000,
    /**
     * Proxy configuration for development
     * Routes API calls to the SelfHelp backend
     */
    proxy: {
      '/index.php': {
        target: 'http://localhost/selfhelp',
        changeOrigin: true,
      },
    },
  },
  
  /**
   * CSS configuration
   */
  css: {
    /**
     * CSS modules configuration (disabled for global styles)
     */
    modules: {
      localsConvention: 'camelCase',
    },
  },
  
  /**
   * Resolve configuration
   */
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
});
