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
 * Custom Code Block Component
 * Renders code with syntax highlighting and copy button
 */
const CodeBlock: React.FC<CodeBlockProps> = ({ inline, className, children, ...props }) => {
  const match = /language-(\w+)/.exec(className || '');
  const language = match ? match[1] : '';
  const codeString = String(children).replace(/\n$/, '');

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
 * Custom components for react-markdown
 */
const markdownComponents: Components = {
  code: CodeBlock as Components['code'],
  pre: PreBlock as Components['pre'],
  a: LinkComponent as Components['a'],
  table: TableComponent as Components['table'],
  blockquote: BlockquoteComponent as Components['blockquote'],
  input: InputComponent as Components['input']
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
