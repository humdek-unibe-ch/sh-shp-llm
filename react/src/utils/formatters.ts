/**
 * Formatter Utility Functions
 * ===========================
 * 
 * Helper functions for formatting data for display.
 * Matches the formatting logic from the vanilla JS implementation.
 * 
 * @module utils/formatters
 */

// ============================================================================
// FILE SIZE FORMATTING
// ============================================================================

/**
 * Format bytes to human-readable size string
 * Matches formatBytes() from vanilla JS
 * 
 * @param bytes - Size in bytes
 * @returns Formatted size string (e.g., "1.5 MB")
 */
export function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// ============================================================================
// DATE/TIME FORMATTING
// ============================================================================

/**
 * Format timestamp to time string (HH:MM)
 * Matches formatTime() from vanilla JS
 * 
 * @param timestamp - ISO timestamp string or Date object
 * @returns Time string in local format
 */
export function formatTime(timestamp: string | Date): string {
  const date = timestamp instanceof Date ? timestamp : new Date(timestamp);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

/**
 * Format date for conversation list display
 * Shows relative time for recent dates, absolute date for older ones
 * Matches formatDate() from vanilla JS
 * 
 * @param dateString - ISO date string
 * @returns Human-readable date/time string
 */
export function formatDate(dateString: string): string {
  const date = new Date(dateString);
  const now = new Date();
  const diffMs = now.getTime() - date.getTime();
  const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
  
  if (diffDays === 0) {
    // Today - show just time
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  } else if (diffDays === 1) {
    // Yesterday
    return 'Yesterday';
  } else if (diffDays < 7) {
    // Within last week - show relative days
    return `${diffDays} days ago`;
  } else {
    // Older - show full date
    return date.toLocaleDateString();
  }
}

// ============================================================================
// FILE ICON UTILITIES
// ============================================================================

/**
 * Get Font Awesome icon class based on file extension
 * Matches getFileIconByExtension() from vanilla JS
 * 
 * @param extension - File extension (without dot)
 * @returns Font Awesome icon class string
 */
export function getFileIconByExtension(extension: string): string {
  const ext = extension.toLowerCase();
  const iconMap: Record<string, string> = {
    // Images
    'jpg': 'fas fa-file-image text-success',
    'jpeg': 'fas fa-file-image text-success',
    'png': 'fas fa-file-image text-success',
    'gif': 'fas fa-file-image text-success',
    'webp': 'fas fa-file-image text-success',
    
    // Documents
    'pdf': 'fas fa-file-pdf text-danger',
    'txt': 'fas fa-file-alt text-secondary',
    'md': 'fas fa-file-alt text-info',
    'csv': 'fas fa-file-csv text-success',
    
    // Code files
    'py': 'fab fa-python text-primary',
    'js': 'fab fa-js-square text-warning',
    'php': 'fab fa-php text-purple',
    'html': 'fab fa-html5 text-danger',
    'css': 'fab fa-css3-alt text-primary',
    'json': 'fas fa-file-code text-warning',
    'xml': 'fas fa-file-code text-info',
    'sql': 'fas fa-database text-primary',
    'sh': 'fas fa-terminal text-dark',
    'yaml': 'fas fa-file-code text-purple',
    'yml': 'fas fa-file-code text-purple'
  };
  
  return iconMap[ext] || 'fas fa-file text-secondary';
}

/**
 * Get CSS class for extension badge based on file type
 * Matches getExtensionBadgeClass() from vanilla JS
 * 
 * @param extension - File extension
 * @param config - File configuration with allowed extensions arrays
 * @returns Badge CSS class
 */
export function getExtensionBadgeClass(
  extension: string,
  imageExtensions: string[],
  codeExtensions: string[]
): string {
  const ext = extension.toLowerCase();
  
  if (imageExtensions.includes(ext)) {
    return 'badge-success';
  }
  if (codeExtensions.includes(ext)) {
    return 'badge-primary';
  }
  return 'badge-secondary';
}

// ============================================================================
// FILE VALIDATION
// ============================================================================

/**
 * Generate a simple hash from file content for duplicate detection
 * Uses first 1KB + file size for performance
 * Matches generateFileHash() from vanilla JS
 * 
 * @param file - File to hash
 * @returns Promise resolving to hash string
 */
export async function generateFileHash(file: File): Promise<string> {
  return new Promise((resolve) => {
    const reader = new FileReader();
    const chunkSize = 1024; // 1KB
    
    reader.onload = (e) => {
      const content = e.target?.result as string || '';
      let hash = file.size.toString() + '_' + file.name.length;
      
      // Add hash from content
      if (content.length > 0) {
        let sum = 0;
        for (let i = 0; i < Math.min(content.length, 100); i++) {
          sum += content.charCodeAt(i);
        }
        hash += '_' + sum.toString(36);
      }
      
      resolve(hash);
    };
    
    reader.onerror = () => {
      // Fallback hash if reading fails
      resolve(file.size + '_' + file.name + '_' + Date.now());
    };
    
    // Read only first chunk for performance
    const slice = file.slice(0, chunkSize);
    reader.readAsText(slice);
  });
}

/**
 * Truncate filename for display
 * 
 * @param fileName - Original filename
 * @param maxLength - Maximum length (default: 20)
 * @returns Truncated filename
 */
export function truncateFileName(fileName: string, maxLength: number = 20): string {
  if (fileName.length <= maxLength) {
    return fileName;
  }
  return fileName.substring(0, maxLength - 3) + '...';
}

/**
 * Get file extension from filename
 * 
 * @param fileName - Filename
 * @returns Extension in lowercase
 */
export function getFileExtension(fileName: string): string {
  const parts = fileName.split('.');
  return parts.length > 1 ? parts.pop()!.toLowerCase() : '';
}

/**
 * Check if file extension is an image type
 * 
 * @param extension - File extension
 * @param imageExtensions - Array of allowed image extensions
 * @returns True if image type
 */
export function isImageExtension(extension: string, imageExtensions: string[]): boolean {
  return imageExtensions.includes(extension.toLowerCase());
}
