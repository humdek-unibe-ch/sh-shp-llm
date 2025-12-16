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
 * @module components/MarkdownRenderer
 */

import React, { useState, useCallback } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import rehypeHighlight from 'rehype-highlight';
import rehypeRaw from 'rehype-raw';
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
 * Resolve asset path to full URL
 * Handles SelfHelp assets, external URLs, and data URLs
 */
const resolveMediaPath = (src: string): string => {
  // External URLs pass through
  if (src.startsWith('http://') || src.startsWith('https://') || src.startsWith('//')) {
    return src;
  }
  
  // Base64 data URLs pass through
  if (src.startsWith('data:')) {
    return src;
  }
  
  // SelfHelp assets - use as-is (relative to site root)
  if (src.startsWith('/assets/') || src.startsWith('assets/')) {
    return src.startsWith('/') ? src : `/${src}`;
  }
  
  // Relative paths - assume assets folder
  return `/assets/${src}`;
};

/**
 * Check if URL is a video based on extension or alt text marker
 */
const isVideoUrl = (src: string, alt?: string): boolean => {
  // Check alt text for video marker (e.g., ![video](path.mp4))
  if (alt?.toLowerCase().startsWith('video')) {
    return true;
  }
  
  // Check file extension
  const videoExtensions = ['.mp4', '.webm', '.ogg', '.ogv'];
  const lowerSrc = src.toLowerCase();
  return videoExtensions.some(ext => lowerSrc.endsWith(ext) || lowerSrc.includes(ext + '?'));
};

/**
 * Parse video options from alt text
 * Format: ![video:controls:autoplay:muted:loop](path)
 */
const parseVideoOptions = (alt: string): { controls: boolean; autoPlay: boolean; muted: boolean; loop: boolean; poster?: string } => {
  const parts = alt.toLowerCase().split(':');
  const options = {
    controls: true,
    autoPlay: false,
    muted: false,
    loop: false,
    poster: undefined as string | undefined
  };
  
  parts.forEach(part => {
    if (part === 'controls') options.controls = true;
    if (part === 'nocontrols') options.controls = false;
    if (part === 'autoplay') options.autoPlay = true;
    if (part === 'muted') options.muted = true;
    if (part === 'loop') options.loop = true;
    if (part.startsWith('poster=')) {
      options.poster = resolveMediaPath(part.substring(7));
    }
  });
  
  // Autoplay requires muted in most browsers
  if (options.autoPlay && !options.muted) {
    options.muted = true;
  }
  
  return options;
};

/**
 * Video Component for embedded videos
 */
const VideoComponent: React.FC<{ src: string; title?: string }> = ({ src, title }) => {
  const resolvedSrc = resolveMediaPath(src);
  const options = parseVideoOptions(title || '');
  
  return (
    <figure className="chat-media-figure my-3">
      <video
        src={resolvedSrc}
        controls={options.controls}
        autoPlay={options.autoPlay}
        muted={options.muted}
        loop={options.loop}
        poster={options.poster}
        className="chat-video rounded"
        style={{ maxWidth: '100%', maxHeight: '400px' }}
        playsInline
      >
        Your browser does not support the video tag.
      </video>
      {title && !title.toLowerCase().startsWith('video') && (
        <figcaption className="text-muted small mt-2 text-center">{title}</figcaption>
      )}
    </figure>
  );
};

/**
 * Custom link component - opens in new tab for external links
 * Also handles video URLs by rendering them as video elements
 */
const LinkComponent: React.FC<{ href?: string; children?: React.ReactNode }> = ({ href, children }) => {
  // Check if this is a video URL - render as video instead of link
  if (href && isVideoUrl(href)) {
    return <VideoComponent src={href} />;
  }
  
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
 * Custom Image/Video Component
 * Renders images and videos with proper styling
 */
const MediaComponent: React.FC<{ src?: string; alt?: string; title?: string }> = ({ src, alt, title }) => {
  if (!src) return null;
  
  const resolvedSrc = resolveMediaPath(src);
  const isVideo = isVideoUrl(src, alt);
  
  if (isVideo) {
    const options = parseVideoOptions(alt || '');
    const cleanAlt = alt?.replace(/^video[:\w]*\s*/i, '') || '';
    
    return (
      <figure className="chat-media-figure my-3">
        <video
          src={resolvedSrc}
          controls={options.controls}
          autoPlay={options.autoPlay}
          muted={options.muted}
          loop={options.loop}
          poster={options.poster}
          className="chat-video rounded"
          style={{ maxWidth: '100%', maxHeight: '400px' }}
          playsInline
        >
          Your browser does not support the video tag.
        </video>
        {cleanAlt && (
          <figcaption className="text-muted small mt-2 text-center">{cleanAlt}</figcaption>
        )}
      </figure>
    );
  }
  
  // Regular image
  return (
    <figure className="chat-media-figure my-3">
      <img
        src={resolvedSrc}
        alt={alt || ''}
        title={title}
        className="chat-image rounded img-fluid"
        style={{ maxHeight: '400px' }}
        loading="lazy"
        onError={(e) => {
          // Show placeholder on error
          const target = e.target as HTMLImageElement;
          target.style.display = 'none';
          const placeholder = document.createElement('div');
          placeholder.className = 'alert alert-warning d-inline-block py-2 px-3';
          placeholder.innerHTML = '<i class="fas fa-image mr-2"></i>Image failed to load';
          target.parentNode?.insertBefore(placeholder, target);
        }}
      />
      {alt && (
        <figcaption className="text-muted small mt-2 text-center">{alt}</figcaption>
      )}
    </figure>
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
 * Custom components for react-markdown
 */
const markdownComponents: Components = {
  code: CodeBlock as Components['code'],
  pre: PreBlock as Components['pre'],
  a: LinkComponent as Components['a'],
  table: TableComponent as Components['table'],
  blockquote: BlockquoteComponent as Components['blockquote'],
  input: InputComponent as Components['input'],
  img: MediaComponent as Components['img']
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
        rehypePlugins={[rehypeRaw, rehypeHighlight]}
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
