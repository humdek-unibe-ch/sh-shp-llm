/**
 * Markdown Renderer Component
 * ============================
 * 
 * Advanced markdown rendering using react-markdown with:
 * - GitHub Flavored Markdown (GFM) support
 * - Syntax highlighting for code blocks
 * - Copy-to-clipboard functionality for code
 * - Proper styling for all markdown elements
 * 
 * @module components/shared/MarkdownRenderer
 */

import React, { useState, useCallback } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import rehypeHighlight from 'rehype-highlight';
import type { Components } from 'react-markdown';

/**
 * Props for MarkdownRenderer
 */
interface MarkdownRendererProps {
  /** The markdown content to render */
  content: string;
  /** Whether this is a streaming message (show cursor) */
  isStreaming?: boolean;
  /** Additional CSS class */
  className?: string;
}

/**
 * Props for code block component
 */
interface CodeBlockProps {
  inline?: boolean;
  className?: string;
  children?: React.ReactNode;
}

/**
 * Copy Button Component for code blocks
 */
const CopyButton: React.FC<{ code: string }> = ({ code }) => {
  const [copied, setCopied] = useState(false);

  const handleCopy = useCallback(async () => {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch (err) {
      // Fallback for older browsers
      const textArea = document.createElement('textarea');
      textArea.value = code;
      textArea.style.position = 'fixed';
      textArea.style.left = '-9999px';
      document.body.appendChild(textArea);
      textArea.select();
      try {
        document.execCommand('copy');
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
      } catch (e) {
        console.error('Copy failed:', e);
      }
      document.body.removeChild(textArea);
    }
  }, [code]);

  return (
    <button
      type="button"
      className={`code-copy-btn ${copied ? 'copied' : ''}`}
      onClick={handleCopy}
      title={copied ? 'Copied!' : 'Copy code'}
    >
      <i className={`fas ${copied ? 'fa-check' : 'fa-copy'}`}></i>
      {copied && <span className="copy-tooltip">Copied!</span>}
    </button>
  );
};

/**
 * Recursively extract text from a React node tree (handles nested spans from syntax highlighting)
 */
const extractTextFromNode = (node: React.ReactNode): string => {
  if (typeof node === 'string' || typeof node === 'number') {
    return String(node);
  }
  if (Array.isArray(node)) {
    return node.map(extractTextFromNode).join('');
  }
  if (React.isValidElement(node)) {
    return extractTextFromNode(node.props.children);
  }
  return '';
};

/**
 * Custom Code Block Component
 * Renders code with syntax highlighting and copy button
 */
const CodeBlock: React.FC<CodeBlockProps> = ({ inline, className, children, ...props }) => {
  const match = /language-(\w+)/.exec(className || '');
  const language = match ? match[1] : '';
  const codeString = extractTextFromNode(children).replace(/\n$/, '');

  if (inline) {
    // Inline code
    return (
      <code className="inline-code" {...props}>
        {children}
      </code>
    );
  }

  // Code block with language
  return (
    <div className="code-block-wrapper">
      {language && (
        <div className="code-block-header">
          <span className="code-language">{language}</span>
          <CopyButton code={codeString} />
        </div>
      )}
      {!language && (
        <div className="code-block-header code-block-header-minimal">
          <CopyButton code={codeString} />
        </div>
      )}
      <pre className={className}>
        <code className={className} {...props}>
          {children}
        </code>
      </pre>
    </div>
  );
};

/**
 * Custom Pre Component (wrapper for code blocks)
 */
const PreBlock: React.FC<{ children?: React.ReactNode }> = ({ children }) => {
  // Don't wrap in pre again, CodeBlock handles it
  return <>{children}</>;
};

/**
 * Custom link component - opens in new tab for external links
 */
const LinkComponent: React.FC<{ href?: string; children?: React.ReactNode }> = ({ href, children }) => {
  const isExternal = href?.startsWith('http') || href?.startsWith('//');
  
  return (
    <a 
      href={href} 
      target={isExternal ? '_blank' : undefined}
      rel={isExternal ? 'noopener noreferrer' : undefined}
      className="md-link"
    >
      {children}
      {isExternal && <i className="fas fa-external-link-alt fa-xs ml-1"></i>}
    </a>
  );
};

/**
 * Custom Table Component
 */
const TableComponent: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <div className="table-responsive">
    <table className="table table-bordered table-sm">{children}</table>
  </div>
);

/**
 * Custom Blockquote Component
 */
const BlockquoteComponent: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <blockquote className="md-blockquote">{children}</blockquote>
);

/**
 * Custom Input Component (for task lists)
 */
interface InputComponentProps {
  type?: string;
  checked?: boolean;
}

const InputComponent: React.FC<InputComponentProps> = ({ type, checked, ...props }) => {
  if (type === 'checkbox') {
    return (
      <input 
        type="checkbox" 
        checked={checked} 
        disabled 
        className="task-checkbox"
        {...props}
      />
    );
  }
  return <input type={type} {...props} />;
};

/**
 * Resolve media path - handles both internal assets and external URLs
 * Internal paths: /assets/..., assets/..., or relative paths
 * External URLs: http://, https://
 */
const resolveMediaPath = (src: string): string => {
  if (!src) return '';
  
  // Already a full URL
  if (src.startsWith('http://') || src.startsWith('https://') || src.startsWith('//')) {
    return src;
  }
  
  // Internal asset path - ensure it starts with /
  if (src.startsWith('/')) {
    return src;
  }
  
  // Relative path - assume it's in the assets folder
  return '/' + src;
};

/**
 * Check if URL is a video based on extension
 */
const isVideoUrl = (url: string): boolean => {
  const videoExtensions = ['.mp4', '.webm', '.ogg', '.mov', '.m4v'];
  const lowerUrl = url.toLowerCase();
  return videoExtensions.some(ext => lowerUrl.includes(ext));
};

/**
 * Custom Image Component with lightbox support
 */
interface ImageComponentProps {
  src?: string;
  alt?: string;
  title?: string;
}

const ImageComponent: React.FC<ImageComponentProps> = ({ src, alt, title }) => {
  const [isLoading, setIsLoading] = useState(true);
  const [hasError, setHasError] = useState(false);
  const [isExpanded, setIsExpanded] = useState(false);
  
  const resolvedSrc = resolveMediaPath(src || '');
  
  // Check if this is actually a video URL in an image tag
  if (isVideoUrl(resolvedSrc)) {
    return <VideoComponent src={resolvedSrc} />;
  }
  
  const handleLoad = () => {
    setIsLoading(false);
    setHasError(false);
  };
  
  const handleError = () => {
    setIsLoading(false);
    setHasError(true);
  };
  
  const handleClick = () => {
    if (!hasError) {
      setIsExpanded(true);
    }
  };
  
  const handleClose = () => {
    setIsExpanded(false);
  };
  
  if (hasError) {
    return (
      <div className="media-error">
        <i className="fas fa-image fa-2x text-muted"></i>
        <span className="text-muted ml-2">{alt || 'Image could not be loaded'}</span>
      </div>
    );
  }
  
  return (
    <>
      <span className="media-wrapper image-wrapper">
        {isLoading && (
          <div className="media-loading">
            <div className="spinner-border spinner-border-sm text-secondary" role="status">
              <span className="sr-only">Loading...</span>
            </div>
          </div>
        )}
        <img
          src={resolvedSrc}
          alt={alt || ''}
          title={title || alt || ''}
          onLoad={handleLoad}
          onError={handleError}
          onClick={handleClick}
          className={`md-image ${isLoading ? 'loading' : ''}`}
          style={{ cursor: 'zoom-in' }}
        />
      </span>
      
      {/* Lightbox overlay */}
      {isExpanded && (
        <div className="media-lightbox" onClick={handleClose}>
          <button className="lightbox-close" onClick={handleClose}>
            <i className="fas fa-times"></i>
          </button>
          <img
            src={resolvedSrc}
            alt={alt || ''}
            className="lightbox-image"
            onClick={(e) => e.stopPropagation()}
          />
          {(alt || title) && (
            <div className="lightbox-caption">{title || alt}</div>
          )}
        </div>
      )}
    </>
  );
};

/**
 * Custom Video Component
 */
interface VideoComponentProps {
  src?: string;
  title?: string;
}

const VideoComponent: React.FC<VideoComponentProps> = ({ src, title }) => {
  const [hasError, setHasError] = useState(false);
  
  const resolvedSrc = resolveMediaPath(src || '');
  
  const handleError = () => {
    setHasError(true);
  };
  
  if (hasError) {
    return (
      <div className="media-error">
        <i className="fas fa-video fa-2x text-muted"></i>
        <span className="text-muted ml-2">Video could not be loaded</span>
      </div>
    );
  }
  
  return (
    <div className="media-wrapper video-wrapper">
      <video
        controls
        preload="metadata"
        className="md-video"
        onError={handleError}
        title={title || ''}
      >
        <source src={resolvedSrc} />
        Your browser does not support the video tag.
      </video>
    </div>
  );
};

/**
 * Custom Paragraph Component
 * Handles special cases like video embeds in markdown
 */
interface ParagraphComponentProps {
  children?: React.ReactNode;
}

const ParagraphComponent: React.FC<ParagraphComponentProps> = ({ children }) => {
  // Check if children contains a video link pattern
  // Pattern: [video](url) or just a video URL on its own line
  if (React.Children.count(children) === 1) {
    const child = React.Children.toArray(children)[0];
    
    // Check if it's a string that looks like a video URL
    if (typeof child === 'string') {
      const trimmed = child.trim();
      if (isVideoUrl(trimmed) && (trimmed.startsWith('http') || trimmed.startsWith('/'))) {
        return <VideoComponent src={trimmed} />;
      }
    }
  }
  
  return <p>{children}</p>;
};

/**
 * Custom components for react-markdown
 */
const markdownComponents: Components = {
  code: CodeBlock as Components['code'],
  pre: PreBlock as Components['pre'],
  a: LinkComponent as Components['a'],
  table: TableComponent as Components['table'],
  blockquote: BlockquoteComponent as Components['blockquote'],
  input: InputComponent as Components['input'],
  img: ImageComponent as Components['img'],
  p: ParagraphComponent as Components['p']
};

/**
 * Markdown Renderer Component
 * 
 * Renders markdown content with syntax highlighting and copy functionality
 */
export const MarkdownRenderer: React.FC<MarkdownRendererProps> = ({
  content,
  isStreaming = false,
  className = ''
}) => {
  return (
    <div className={`markdown-content ${className}`}>
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        rehypePlugins={[rehypeHighlight]}
        components={markdownComponents}
      >
        {content}
      </ReactMarkdown>
      {isStreaming && (
        <span className="streaming-cursor"></span>
      )}
    </div>
  );
};

export default MarkdownRenderer;


