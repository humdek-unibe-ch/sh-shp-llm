const gulp = require('gulp');
const concat = require('gulp-concat');
const uglify = require('gulp-uglify');
const cleanCSS = require('gulp-clean-css');
const rename = require('gulp-rename');

// Build CSS
gulp.task('css', function() {
    return gulp.src('../server/component/style/llmchat/css/*.css')
        .pipe(concat('llmchat.min.css'))
        .pipe(cleanCSS())
        .pipe(gulp.dest('../css/ext/'));
});

// Build JS
gulp.task('js', function() {
    return gulp.src('../server/component/style/llmchat/js/*.js')
        .pipe(concat('llmchat.min.js'))
        .pipe(uglify())
        .pipe(gulp.dest('../js/ext/'));
});

// Watch files
gulp.task('watch', function() {
    gulp.watch('../server/component/style/llmchat/css/*.css', gulp.series('css'));
    gulp.watch('../server/component/style/llmchat/js/*.js', gulp.series('js'));
});

// Default task
gulp.task('default', gulp.parallel('css', 'js'));
