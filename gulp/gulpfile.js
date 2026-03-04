/**
 * Gulp Build Configuration for LLM Chat Plugin
 * =============================================
 * 
 * Build tasks for compiling and bundling React assets via Vite.
 * 
 * Tasks:
 * - `gulp react-build`: Build all React bundles (chat, admin, scripts)
 * - `gulp react-install`: Install React dependencies
 * - `gulp watch-react`: Watch React files for changes
 * - `gulp clean`: Remove built files
 * - `gulp default`: Build everything
 * 
 * Output:
 * - JS:  js/ext/llm-chat.umd.js, js/ext/llm-admin.umd.js, js/ext/llm-scripts.umd.js
 * - CSS: css/ext/llm-chat.css, css/ext/llm-admin.css, css/ext/llm-scripts.css
 */

const gulp = require('gulp');
const { exec } = require('child_process');
const path = require('path');

const paths = {
  react: {
    src: path.join(__dirname, '../react'),
    jsOutput: path.join(__dirname, '../js/ext'),
    cssOutput: path.join(__dirname, '../css/ext')
  }
};

/**
 * Install React dependencies
 */
gulp.task('react-install', function(cb) {
  console.log('Installing React dependencies...');
  exec('npm install', { cwd: paths.react.src }, function(err, stdout, stderr) {
    if (stdout) console.log(stdout);
    if (stderr) console.error(stderr);
    if (err) {
      console.error('Failed to install React dependencies:', err);
    } else {
      console.log('React dependencies installed successfully.');
    }
    cb(err);
  });
});

/**
 * Build all React bundles (chat, admin, scripts)
 * CSS files are automatically moved to css/ext/ by the npm build script
 */
gulp.task('react-build', function(cb) {
  console.log('Building React bundles...');
  exec('npm run build', { cwd: paths.react.src }, function(err, stdout, stderr) {
    if (stdout) console.log(stdout);
    if (stderr) console.error(stderr);
    if (err) {
      console.error('React build failed:', err);
    } else {
      console.log('React bundles built successfully.');
      console.log('Output:');
      console.log('  JS:  js/ext/llm-chat.umd.js, llm-admin.umd.js, llm-scripts.umd.js, llm-form.umd.js');
      console.log('  CSS: css/ext/llm-chat.css, llm-admin.css, llm-scripts.css, llm-form.css');
    }
    cb(err);
  });
});

/**
 * Watch React files for changes during development
 */
gulp.task('watch-react', function(cb) {
  console.log('Starting React watch mode...');
  exec('npm run watch', { cwd: paths.react.src }, function(err, stdout, stderr) {
    if (stdout) console.log(stdout);
    if (stderr) console.error(stderr);
    cb(err);
  });
});

/**
 * Full build task
 */
gulp.task('build', gulp.series('react-build'));

/**
 * Default task
 */
gulp.task('default', gulp.series('build'));

/**
 * Clean built files
 */
gulp.task('clean', function(cb) {
  const del = require('del');
  del([
    path.join(paths.react.jsOutput, 'llm-chat.umd.js'),
    path.join(paths.react.jsOutput, 'llm-admin.umd.js'),
    path.join(paths.react.jsOutput, 'llm-scripts.umd.js'),
    path.join(paths.react.cssOutput, 'llm-chat.css'),
    path.join(paths.react.cssOutput, 'llm-admin.css'),
    path.join(paths.react.cssOutput, 'llm-scripts.css')
  ]).then(() => {
    console.log('Cleaned build files.');
    cb();
  }).catch(cb);
});

/**
 * Help task
 */
gulp.task('help', function(cb) {
  console.log(`
LLM Chat Plugin - Gulp Tasks
=============================

Available tasks:

  gulp                  - Build everything (default)
  gulp build            - Build all React bundles
  gulp react-install    - Install React dependencies
  gulp react-build      - Build React bundles
  gulp watch-react      - Watch React files for changes
  gulp clean            - Remove built files
  gulp help             - Show this help

First-time setup:
  1. cd gulp
  2. npm install
  3. gulp react-install
  4. gulp build

Output:
  JS:  js/ext/llm-chat.umd.js, llm-admin.umd.js, llm-scripts.umd.js
  CSS: css/ext/llm-chat.css, llm-admin.css, llm-scripts.css
`);
  cb();
});
