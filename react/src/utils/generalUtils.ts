/**
 * General utility functions
 * Extracted from types/index.ts to separate concerns
 */

/**
 * Format bytes to human-readable string
 * Matches formatBytes from vanilla JS
 * @param bytes Number of bytes
 * @returns Human-readable string
 */
export function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}