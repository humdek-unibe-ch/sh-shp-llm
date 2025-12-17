/**
 * Structured Response Renderer Component
 * =======================================
 * 
 * Renders structured JSON responses from the LLM.
 * Supports:
 * - Text blocks with different types (paragraph, heading, list, etc.)
 * - Optional forms that users can fill out or skip
 * - Media items (images, videos)
 * - Next step suggestions
 * - Progress milestone celebrations
 * 
 * @module components/shared/StructuredResponseRenderer
 */

import React from 'react';
import { MarkdownRenderer } from './MarkdownRenderer';
import { FormRenderer } from './FormRenderer';
import type {
  StructuredResponse,
  TextBlock,
  StructuredForm,
  NextStep,
  structuredFormToFormDefinition,
  FormDefinition
} from '../../../types';

/**
 * Props for StructuredResponseRenderer
 */
interface StructuredResponseRendererProps {
  /** The structured response to render */
  response: StructuredResponse;
  /** Whether this is the last message (forms are interactive) */
  isLastMessage?: boolean;
  /** Callback when form is submitted */
  onFormSubmit?: (values: Record<string, string | string[]>, readableText: string) => void;
  /** Whether form submission is in progress */
  isFormSubmitting?: boolean;
  /** Callback when a suggestion is clicked */
  onSuggestionClick?: (suggestion: string) => void;
}

/**
 * Render a single text block with appropriate styling
 */
const TextBlockRenderer: React.FC<{ block: TextBlock }> = ({ block }) => {
  const { type, content, level } = block;

  switch (type) {
    case 'heading': {
      const HeadingTag = `h${level || 2}` as keyof JSX.IntrinsicElements;
      return (
        <HeadingTag className="structured-heading mb-3">
          <MarkdownRenderer content={content} />
        </HeadingTag>
      );
    }

    case 'list':
      return (
        <div className="structured-list mb-3">
          <MarkdownRenderer content={content} />
        </div>
      );

    case 'quote':
      return (
        <blockquote className="structured-quote border-left pl-3 py-2 mb-3">
          <MarkdownRenderer content={content} />
        </blockquote>
      );

    case 'info':
      return (
        <div className="alert alert-info structured-info mb-3">
          <i className="fas fa-info-circle mr-2"></i>
          <MarkdownRenderer content={content} />
        </div>
      );

    case 'warning':
      return (
        <div className="alert alert-warning structured-warning mb-3">
          <i className="fas fa-exclamation-triangle mr-2"></i>
          <MarkdownRenderer content={content} />
        </div>
      );

    case 'success':
      return (
        <div className="alert alert-success structured-success mb-3">
          <i className="fas fa-check-circle mr-2"></i>
          <MarkdownRenderer content={content} />
        </div>
      );

    case 'tip':
      return (
        <div className="alert alert-secondary structured-tip mb-3">
          <i className="fas fa-lightbulb mr-2"></i>
          <MarkdownRenderer content={content} />
        </div>
      );

    case 'paragraph':
    default:
      return (
        <div className="structured-paragraph mb-3">
          <MarkdownRenderer content={content} />
        </div>
      );
  }
};

/**
 * Render next step suggestions as quick-reply buttons
 */
const NextStepRenderer: React.FC<{
  nextStep: NextStep;
  onSuggestionClick?: (suggestion: string) => void;
}> = ({ nextStep, onSuggestionClick }) => {
  const { prompt, suggestions } = nextStep;

  return (
    <div className="structured-next-step mt-4 pt-3 border-top">
      {prompt && (
        <p className="text-muted mb-3">
          <i className="fas fa-arrow-right mr-2"></i>
          {prompt}
        </p>
      )}

      {suggestions && suggestions.length > 0 && (
        <div className="suggestion-buttons d-flex flex-wrap gap-2">
          {suggestions.map((suggestion, index) => (
            <button
              key={index}
              type="button"
              className="btn btn-outline-primary btn-sm suggestion-btn"
              onClick={() => onSuggestionClick?.(suggestion)}
            >
              {suggestion}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

/**
 * Render milestone celebration banner
 */
const MilestoneBanner: React.FC<{ milestone: string }> = ({ milestone }) => {
  return (
    <div className="milestone-banner alert alert-success d-flex align-items-center mb-4">
      <span className="milestone-icon mr-3" role="img" aria-label="celebration">
        🎉
      </span>
      <div>
        <strong>Milestone Reached!</strong>
        <span className="ml-2">You've completed {milestone} of the module!</span>
      </div>
    </div>
  );
};

/**
 * Convert StructuredForm to FormDefinition for backwards compatibility
 */
const structuredFormToFormDefinitionLocal = (form: StructuredForm): FormDefinition => ({
  type: 'form',
  title: form.title,
  description: form.description,
  fields: form.fields,
  submitLabel: form.submit_label
});

/**
 * Structured Response Renderer
 * 
 * Main component that renders a complete structured response
 */
export const StructuredResponseRenderer: React.FC<StructuredResponseRendererProps> = ({
  response,
  isLastMessage = false,
  onFormSubmit,
  isFormSubmitting = false,
  onSuggestionClick
}) => {
  const { content, meta } = response;
  const { text_blocks, forms, media, next_step } = content;

  // Check for milestone celebration
  const milestone = meta.progress?.milestone;

  return (
    <div className="structured-response">
      {/* Milestone celebration banner */}
      {milestone && <MilestoneBanner milestone={milestone} />}

      {/* Text blocks */}
      {text_blocks.map((block, index) => (
        <TextBlockRenderer key={index} block={block} />
      ))}

      {/* Media items */}
      {media && media.length > 0 && (
        <div className="structured-media mb-4">
          {media.map((item, index) => {
            if (item.type === 'image') {
              return (
                <figure key={index} className="figure mb-3">
                  <img
                    src={item.src}
                    alt={item.alt || ''}
                    className="figure-img img-fluid rounded"
                  />
                  {item.caption && (
                    <figcaption className="figure-caption text-center">
                      {item.caption}
                    </figcaption>
                  )}
                </figure>
              );
            }
            if (item.type === 'video') {
              return (
                <div key={index} className="video-wrapper mb-3">
                  <video controls className="w-100 rounded">
                    <source src={item.src} />
                    Your browser does not support video playback.
                  </video>
                  {item.caption && (
                    <p className="text-center text-muted small mt-1">
                      {item.caption}
                    </p>
                  )}
                </div>
              );
            }
            return null;
          })}
        </div>
      )}

      {/* Forms - only interactive on last message */}
      {forms && forms.length > 0 && (
        <div className="structured-forms">
          {forms.map((form, index) => {
            const formDefinition = structuredFormToFormDefinitionLocal(form);
            
            if (isLastMessage && onFormSubmit) {
              // Interactive form
              return (
                <div key={form.id || index} className="structured-form-wrapper mb-4">
                  {form.optional && (
                    <p className="text-muted small mb-2">
                      <i className="fas fa-info-circle mr-1"></i>
                      This form is optional. You can also type your response freely.
                    </p>
                  )}
                  <FormRenderer
                    formDefinition={formDefinition}
                    onSubmit={onFormSubmit}
                    isSubmitting={isFormSubmitting}
                  />
                </div>
              );
            }
            
            // Historical form - read-only display
            return (
              <div key={form.id || index} className="structured-form-historical card mb-4">
                <div className="card-header">
                  {form.title || 'Form'}
                </div>
                <div className="card-body">
                  {form.description && (
                    <p className="text-muted">{form.description}</p>
                  )}
                  <p className="text-muted small">
                    <i className="fas fa-history mr-1"></i>
                    Form was presented here
                  </p>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Next step suggestions */}
      {next_step && isLastMessage && (
        <NextStepRenderer
          nextStep={next_step}
          onSuggestionClick={onSuggestionClick}
        />
      )}

      {/* Progress indicator for this response */}
      {meta.progress && meta.progress.newly_covered && meta.progress.newly_covered.length > 0 && (
        <div className="progress-update text-muted small mt-3 pt-2 border-top">
          <i className="fas fa-check-circle text-success mr-1"></i>
          Topics covered: {meta.progress.newly_covered.join(', ')}
        </div>
      )}
    </div>
  );
};

export default StructuredResponseRenderer;

