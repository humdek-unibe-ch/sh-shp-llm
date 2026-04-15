/**
 * Modal wrapper for the Prompt Builder workspace.
 *
 * Opens a full-screen dialog where the admin can instruct the LLM to
 * auto-generate or refine a system prompt. Delegates all editing logic
 * to PromptBuilderWorkspace.
 *
 * @module components/prompts/PromptBuilderModal
 */
import React, { useEffect, useState } from 'react';
import { Modal } from 'react-bootstrap';
import { PromptBuilderWorkspace } from './PromptBuilderWorkspace';
import type { createPromptLabApi } from './promptApi';
import type { PromptDescriptor, PromptModel, PromptVariableDefinition } from './promptTypes';

interface PromptBuilderModalProps {
  show: boolean;
  onHide: () => void;
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  currentPrompt: string;
  models: PromptModel[];
  defaultModel?: string | null;
  onApplySuggestion: (promptTemplate: string, variables: PromptVariableDefinition[], changeSummary: string) => void;
  disabled?: boolean;
  title?: string;
  preferredExampleDatasetId?: number | null;
  showAutoApplyOnClose?: boolean;
}

/** Modal dialog for prompt builder modal. */
export const PromptBuilderModal: React.FC<PromptBuilderModalProps> = ({
  show,
  onHide,
  api,
  descriptor,
  currentPrompt,
  models,
  defaultModel,
  onApplySuggestion,
  disabled = false,
  title = 'Build With AI',
  preferredExampleDatasetId = null,
  showAutoApplyOnClose = true,
}) => {
  const [closeHandler, setCloseHandler] = useState<() => void>(() => onHide);

  useEffect(() => {
    setCloseHandler(() => onHide);
  }, [onHide, show]);

  return (
    <Modal show={show} onHide={() => closeHandler()} centered dialogClassName="prompt-modal-90 prompt-builder-modal">
      <Modal.Header closeButton className="py-2">
        <Modal.Title className="h6">
          <i className="fas fa-magic mr-2"></i>
          {title}
        </Modal.Title>
      </Modal.Header>
      <Modal.Body>
        <PromptBuilderWorkspace
          show={show}
          api={api}
          descriptor={descriptor}
          currentPrompt={currentPrompt}
          models={models}
          defaultModel={defaultModel}
          onApplySuggestion={onApplySuggestion}
          onClose={onHide}
          registerCloseHandler={(handler) => setCloseHandler(() => handler)}
          disabled={disabled}
          preferredExampleDatasetId={preferredExampleDatasetId}
          showAutoApplyOnClose={showAutoApplyOnClose}
          showApplySuggestionButton={false}
        />
      </Modal.Body>
    </Modal>
  );
};
