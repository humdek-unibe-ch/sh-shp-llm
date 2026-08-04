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
import { normalizeEscapedText } from '../../../utils/text';
import {
  isAudioUrl,
  isVideoUrl,
  promoteBareMediaLines,
  resolveMediaPath
} from '../../../utils/mediaPath';

/**
 * Props for MarkdownRenderer
 */
interface MarkdownRendererProps {
  /** The markdown content to render */
  content: string;
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
 * Handles SelfHelp assets (with BASE_PATH), external URLs, and data URLs
 */
// resolveMediaPath / isVideoUrl / isAudioUrl imported from utils/mediaPath

interface PlaybackOptions {
  controls: boolean;
  autoPlay: boolean;
  muted: boolean;
  loop: boolean;
  poster?: string;
}

/**
 * Parse playback options from alt/title text.
 * Format: ![video:controls:autoplay:muted:loop](path)
 *         ![audio:controls:autoplay:loop](path)
 */
const parsePlaybackOptions = (alt: string): PlaybackOptions => {
  const parts = alt.toLowerCase().split(':');
  const options: PlaybackOptions = {
    controls: true,
    autoPlay: false,
    muted: false,
    loop: false,
    poster: undefined
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

/** Strip leading type/option tokens from alt text for captions. */
const cleanMediaCaption = (alt: string | undefined, kind: 'video' | 'audio'): string => {
  if (!alt) return '';
  const pattern = kind === 'video'
    ? /^video(?::[^\s]*)?\s*/i
    : /^audio(?::[^\s]*)?\s*/i;
  return alt.replace(pattern, '').trim();
};

/**
 * Video Component for embedded videos
 */
const VideoComponent: React.FC<{ src: string; title?: string; alt?: string }> = ({ src, title, alt }) => {
  const resolvedSrc = resolveMediaPath(src);
  const options = parsePlaybackOptions(alt || title || '');
  const caption = cleanMediaCaption(alt, 'video') || (title && !title.toLowerCase().startsWith('video') ? title : '');

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
      {caption && (
        <figcaption className="text-muted small mt-2 text-center">{caption}</figcaption>
      )}
    </figure>
  );
};

/**
 * Audio Component for embedded audio players
 */
const AudioComponent: React.FC<{ src: string; title?: string; alt?: string }> = ({ src, title, alt }) => {
  const resolvedSrc = resolveMediaPath(src);
  const options = parsePlaybackOptions(alt || title || '');
  const caption = cleanMediaCaption(alt, 'audio') || (title && !title.toLowerCase().startsWith('audio') ? title : '');

  return (
    <figure className="chat-media-figure chat-audio-figure my-3">
      <audio
        src={resolvedSrc}
        controls={options.controls}
        autoPlay={options.autoPlay}
        muted={options.muted}
        loop={options.loop}
        className="chat-audio"
        preload="metadata"
      >
        Your browser does not support the audio element.
      </audio>
      {caption && (
        <figcaption className="text-muted small mt-2 text-center">{caption}</figcaption>
      )}
    </figure>
  );
};

/**
 * Custom link component - opens in new tab for external links.
 * Bare audio/video URLs (markdown autolinks) render as players.
 */
const LinkComponent: React.FC<{ href?: string; children?: React.ReactNode }> = ({ href, children }) => {
  if (href && isAudioUrl(href)) {
    return <AudioComponent src={href} />;
  }
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
 * Custom Image / Video / Audio Component
 * Renders markdown `![alt](url)` as image, or as video/audio when detected.
 */
const MediaComponent: React.FC<{ src?: string; alt?: string; title?: string }> = ({ src, alt, title }) => {
  if (!src) return null;

  const resolvedSrc = resolveMediaPath(src);

  if (isAudioUrl(src, alt)) {
    return <AudioComponent src={resolvedSrc} alt={alt} title={title} />;
  }

  if (isVideoUrl(src, alt)) {
    return <VideoComponent src={resolvedSrc} alt={alt} title={title} />;
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

/** InputComponent React component. */
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
  className = ''
}) => {
  const normalized = promoteBareMediaLines(normalizeEscapedText(content));

  return (
    <div className={`markdown-content ${className}`}>
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        rehypePlugins={[rehypeRaw, rehypeHighlight]}
        components={markdownComponents}
      >
        {normalized}
      </ReactMarkdown>
    </div>
  );
};

/** Default export for this module. */
export default MarkdownRenderer;
