/**
 * Gulp Build Configuration for LLM Chat Plugin
 * =============================================
 * 
 * Build tasks for compiling and bundling assets:
 * - React component (UMD bundle via Vite)
 * - Legacy CSS/JS (for backward compatibility)
 * 
 * Tasks:
 * - `gulp react-build`: Build React component (UMD bundle)
 * - `gulp react-install`: Install React dependencies
 * - `gulp css`: Build legacy CSS
 * - `gulp js`: Build legacy JS
 * - `gulp watch`: Watch legacy files for changes
 * - `gulp watch-react`: Watch React files for changes
 * - `gulp default`: Build everything
 * 
 * Output Locations:
 * - React UMD bundle: ../js/ext/llm-chat.umd.js
 * - React CSS: ../css/ext/llm-chat.css
 * - Legacy CSS: ../css/ext/llmchat.min.css
 * - Legacy JS: ../js/ext/llmchat.min.js
 */

const gulp = require('gulp');
const concat = require('gulp-concat');
const uglify = require('gulp-uglify');
const cleanCSS = require('gulp-clean-css');
const rename = require('gulp-rename');
const { exec } = require('child_process');
const path = require('path');
const fs = require('fs');

// Paths
const paths = {
  react: {
    src: path.join(__dirname, '../react'),
    output: path.join(__dirname, '../js/ext')
  },
  legacy: {
    css: {
      src: path.join(__dirname, '../server/component/style/llmchat/css/*.css'),
      dest: path.join(__dirname, '../css/ext')
    },
    js: {
      src: path.join(__dirname, '../server/component/style/llmchat/js/*.js'),
      dest: path.join(__dirname, '../js/ext')
    }
  }
};

/**
 * Install React dependencies
 * Run this first before building React component
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
 * Build React component as UMD bundle
 * Output: ../js/ext/llm-chat.umd.js and ../css/ext/llm-chat.css
 */
gulp.task('react-build', function(cb) {
  console.log('Building React component...');

  // Use npm run build which runs tsc && vite build
  exec('npm run build', { cwd: paths.react.src }, function(err, stdout, stderr) {
    if (stdout) console.log(stdout);
    if (stderr) console.error(stderr);
    if (err) {
      console.error('React build failed:', err);
    } else {
      console.log('React component built successfully.');
      console.log('Output files:');
      console.log('  - js/ext/llm-chat.umd.js');
      console.log('  - css/ext/llm-chat.css');
    }
    cb(err);
  });
});

/**
 * Move React CSS from js/ext/ to css/ext/ after build
 */
gulp.task('move-react-css', function(cb) {
  console.log('Moving React CSS to css folder...');

  // First move the CSS file
  gulp.src(path.join(paths.react.output, 'llm-chat.css'))
    .pipe(gulp.dest(path.join(__dirname, '../css/ext')))
    .on('end', function() {
      console.log('React CSS moved to: css/ext/llm-chat.css');

      // Then remove the original CSS file from js/ext/
      const originalCssPath = path.join(paths.react.output, 'llm-chat.css');
      fs.unlink(originalCssPath, function(err) {
        if (err) {
          console.warn('Warning: Could not remove original CSS file:', err.message);
        } else {
          console.log('Cleaned up original CSS file from js/ext/');
        }
        cb();
      });
    });
});

/**
 * Watch React files for changes during development
 * Uses Vite's built-in watch mode
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
 * Build legacy CSS (backward compatibility)
 * Combines and minifies CSS files from the legacy component
 */
gulp.task('css', function() {
  console.log('Building legacy CSS...');
  return gulp.src(paths.legacy.css.src)
    .pipe(concat('llmchat.min.css'))
    .pipe(cleanCSS({ level: 2 }))
    .pipe(gulp.dest(paths.legacy.css.dest))
    .on('end', function() {
      console.log('Legacy CSS built: css/ext/llmchat.min.css');
    });
});

/**
 * Build legacy JS (backward compatibility)
 * Combines and minifies JS files from the legacy component
 */
gulp.task('js', function() {
  console.log('Building legacy JS...');
  return gulp.src(paths.legacy.js.src)
    .pipe(concat('llmchat.min.js'))
    .pipe(uglify())
    .pipe(gulp.dest(paths.legacy.js.dest))
    .on('end', function() {
      console.log('Legacy JS built: js/ext/llmchat.min.js');
    });
});

/**
 * Watch legacy files for changes
 */
gulp.task('watch', function() {
  console.log('Watching legacy files for changes...');
  gulp.watch(paths.legacy.css.src, gulp.series('css'));
  gulp.watch(paths.legacy.js.src, gulp.series('js'));
});

/**
 * Build legacy assets only (CSS + JS)
 */
gulp.task('legacy', gulp.parallel('css', 'js'));

/**
 * Full build task
 * Builds both React component and legacy assets
 */
gulp.task('build', gulp.series(
  gulp.parallel('css', 'js'),
  gulp.series('react-build', 'move-react-css')
));

/**
 * Default task
 * Runs the full build
 */
gulp.task('default', gulp.series('build'));

/**
 * Clean task (optional - for future use)
 * Removes built files
 */
gulp.task('clean', function(cb) {
  const del = require('del');
  del([
    paths.legacy.css.dest + '/llmchat.min.css',
    paths.legacy.js.dest + '/llmchat.min.js',
    paths.react.output + '/llm-chat.umd.js',
    path.join(__dirname, '../css/ext/llm-chat.css')
  ]).then(() => {
    console.log('Cleaned build files.');
    cb();
  }).catch(cb);
});

/**
 * Help task
 * Displays available tasks
 */
gulp.task('help', function(cb) {
  console.log(`
LLM Chat Plugin - Gulp Tasks
=============================

Available tasks:

  gulp                  - Build everything (default)
  gulp build            - Build React + legacy assets
  gulp react-install    - Install React dependencies
  gulp react-build      - Build React component only
  gulp legacy           - Build legacy CSS/JS only
  gulp css              - Build legacy CSS only
  gulp js               - Build legacy JS only
  gulp watch            - Watch legacy files
  gulp watch-react      - Watch React files
  gulp clean            - Remove built files
  gulp help             - Show this help

First-time setup:
  1. cd gulp
  2. npm install
  3. gulp react-install
  4. gulp build

Output locations:
  - React JS: js/ext/llm-chat.umd.js
  - React CSS: css/ext/llm-chat.css
  - Legacy CSS: css/ext/llmchat.min.css
  - Legacy JS: js/ext/llmchat.min.js

Development:
  gulp watch-react      - For React development with hot reload
  gulp watch            - For legacy CSS/JS changes
`);
  cb();
});
