/**
 * Move CSS files from js/ext to css/ext after build
 * This ensures CSS files are in the correct directory for the application
 */

const fs = require('fs');
const path = require('path');

const sourceDir = path.join(__dirname, '..', 'js', 'ext');
const targetDir = path.join(__dirname, '..', 'css', 'ext');

// Ensure target directory exists
if (!fs.existsSync(targetDir)) {
  fs.mkdirSync(targetDir, { recursive: true });
  console.log('Created directory:', targetDir);
}

// Move CSS files
const files = fs.readdirSync(sourceDir);
const cssFiles = files.filter(file => file.endsWith('.css'));

if (cssFiles.length === 0) {
  console.log('No CSS files found to move');
  process.exit(0);
}

cssFiles.forEach(cssFile => {
  const sourcePath = path.join(sourceDir, cssFile);
  const targetPath = path.join(targetDir, cssFile);

  try {
    fs.renameSync(sourcePath, targetPath);
    console.log(`Moved ${cssFile} to css/ext/`);
  } catch (error) {
    console.error(`Error moving ${cssFile}:`, error.message);
    process.exit(1);
  }
});

console.log('CSS files moved successfully');




