/**
 * Interview Chat
 * ==============
 *
 * The coach pane of the form interview. It is the llmChat panel with
 * interview behaviour: the same header, bubbles, avatars, composer and
 * quick-reply buttons - only the actions differ (start over, minimize,
 * save).
 *
 * The participant's message is shown immediately (optimistic bubble)
 * while the coach is thinking. The composer is never disabled by
 * background work - typing ahead keeps the cursor where it is; only
 * sending waits for the running turn. Bubble/avatar markup and palette
 * variables are shared with llmChat, so `llm_chat_appearance` themes
 * both.
 */

import React, { memo, useCallback, useEffect, useMemo, useRef } from 'react';
import { Card, Spinner } from 'react-bootstrap';
import type { LlmChatConfig } from '../../../types';
import { DEFAULT_FILE_CONFIG } from '../../../types';
import type {
  InterviewConfig,
  InterviewState,
  InterviewUiLabels,
} from '../../../types/interview';
import { basePageUrl } from '../../../utils/interviewApi';
import { MarkdownRenderer } from '../shared/MarkdownRenderer';
import { ChatPanelHeader, HeaderButton } from '../shared/ChatPanelHeader';
import { MessageInput } from '../shared/MessageInput';
import type { MessageInputConfig, MessageInputHandle } from '../shared/MessageInput';
import { BubbleAvatar, getPaletteVariables } from '../shared/MessageList';
import type { ChatMessage } from './interviewUtils';
import { choicesOf } from './interviewUtils';

export interface ChatNotice {
  message: string;
  /** Present when the failed step can be repeated without new input. */
  retry?: () => void;
  /** `info` for something that fixed itself (a draft refreshed elsewhere). */
  tone?: 'danger' | 'info';
  /** When true, `message` is markdown (e.g. CMS danger_blocked_message). */
  markdown?: boolean;
}

interface InterviewChatProps {
  config: InterviewConfig;
  labels: InterviewUiLabels;
  state: InterviewState | null;
  messages: ChatMessage[];
  /** Optimistic participant message that is not in the state yet. */
  pendingText: string | null;
  /** The coach is working on an answer. */
  thinking: boolean;
  /** The scope is loading (first open). */
  opening: boolean;
  /** Sending waits (a turn or the save is running); typing stays possible. */
  sendBlocked: boolean;
  /** The entry is saved - transcript only (not a crisis block). */
  finished: boolean;
  /** Danger/crisis block: hide composer without treating the entry as saved. */
  conversationBlocked?: boolean;
  /** The save request is running. */
  approving: boolean;
  /** Everything needed to save is there: offer the Save button here too. */
  canApprove: boolean;
  notice: ChatNotice | null;
  onDismissNotice: () => void;
  /** Resolves true when the message reached the server. */
  onSend: (text: string) => Promise<boolean>;
  onApprove: () => void;
  onRestart: () => void;
  /** Page mode only: back to the list. A modal closes through its own header. */
  onMinimize?: () => void;
  /** Header actions wait while a save is running. */
  busyLock: boolean;
}

const Bubble = memo(function Bubble({
  message,
  avatarConfig,
}: {
  message: ChatMessage;
  avatarConfig: LlmChatConfig;
}) {
  const isUser = message.role === 'user';
  return (
    <div className={`message-wrapper ${isUser ? 'user' : 'assistant'} llm-iv-msg`}>
      <BubbleAvatar isUser={isUser} config={avatarConfig} />
      <div className="message-bubble">
        <div className="message-content">
          {isUser ? (
            <div style={{ whiteSpace: 'pre-wrap' }}>{message.content}</div>
          ) : (
            <MarkdownRenderer content={message.content} />
          )}
        </div>
      </div>
    </div>
  );
});

export const InterviewChat: React.FC<InterviewChatProps> = ({
  config,
  labels,
  state,
  messages,
  pendingText,
  thinking,
  opening,
  sendBlocked,
  finished,
  conversationBlocked = false,
  approving,
  canApprove,
  notice,
  onDismissNotice,
  onSend,
  onApprove,
  onRestart,
  onMinimize,
  busyLock,
}) => {
  const transcriptRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<MessageInputHandle>(null);

  const avatarConfig = useMemo(
    () => ({ chatAppearance: config.appearance } as unknown as LlmChatConfig),
    [config.appearance]
  );
  const palette = useMemo(() => getPaletteVariables(config.appearance), [config.appearance]);

  // The composer reads this slice of the llmChat configuration; nothing
  // else of it applies to an interview (no attachments, no form mode).
  const inputConfig = useMemo<MessageInputConfig>(
    () => ({
      enableSpeechToText: config.speech.enabled,
      speechToTextModel: '',
      sectionId: config.sectionId,
      fileConfig: DEFAULT_FILE_CONFIG,
      emptyMessageError: labels.errorEmpty || labels.notAnswered,
      enableFileUploads: false,
      isVisionModel: false,
      isFloatingMode: false,
      enableFormMode: false,
      formModeActiveTitle: '',
      formModeActiveDescription: '',
      messagePlaceholder: config.labels.inputPlaceholder,
      acceptedFileTypes: '',
      attachFilesTitle: '',
      noVisionSupportTitle: '',
      noVisionSupportText: '',
      clearButtonLabel: labels.clearInput,
      sendMessageTitle: config.labels.send,
      removeFileTitle: '',
    }),
    [config, labels]
  );
  const speech = useMemo(
    () => ({
      endpoint: basePageUrl(),
      requestFields: {
        __llm_interview_action: 'transcribe',
        section_id: String(config.sectionId),
        csrf_token: config.csrfToken,
      },
    }),
    [config.sectionId, config.csrfToken]
  );
  const micLabels = useMemo(
    () => ({ start: labels.mic, stop: labels.micStop, busy: labels.micBusy }),
    [labels.mic, labels.micStop, labels.micBusy]
  );

  // Quick replies: the coach's own suggestions first, otherwise the
  // closed options of the active question.
  const hideComposer = finished || conversationBlocked;

  const quickReplies = useMemo(() => {
    if (hideComposer || thinking || sendBlocked) return [] as string[];
    const suggested = (state?.suggestions ?? []).filter((s) => s.trim() !== '');
    if (suggested.length > 0) return suggested;
    return choicesOf(state).map((o) => o.label);
  }, [state, hideComposer, thinking, sendBlocked]);

  // Keep the newest entry in view; the transcript never grows the page.
  useEffect(() => {
    const el = transcriptRef.current;
    if (!el) return;
    const id = window.requestAnimationFrame(() => {
      el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
    });
    return () => window.cancelAnimationFrame(id);
  }, [messages.length, pendingText, thinking, opening, quickReplies.length, canApprove]);

  const submit = useCallback(
    (text: string) => {
      const value = text.trim();
      if (!value) return;
      void onSend(value).then((delivered) => {
        // Never reached the server: give the words back, unless the
        // participant has already started typing something else.
        if (!delivered) inputRef.current?.restore(value);
      });
    },
    [onSend]
  );

  const noFiles = useMemo(() => [], []);
  const ignoreFiles = useCallback(() => undefined, []);

  return (
    <Card className="border-0 shadow-sm llm-chat-panel llm-iv-panel llm-iv-chat">
      <ChatPanelHeader
        title={config.labels.title || labels.coachTitle}
        status={config.labels.subtitle || labels.coachStatus}
        statusIcon="fa-robot"
        busy={thinking || opening}
        iconClass={labels.launcherIcon}
        actions={
          <>
            {state ? (
              <HeaderButton
                icon="fa-redo"
                label={labels.restart}
                onClick={onRestart}
                disabled={busyLock || thinking}
              />
            ) : null}
            {onMinimize ? (
              <HeaderButton
                icon="fa-minus"
                label={labels.minimize}
                onClick={onMinimize}
                disabled={busyLock}
              />
            ) : null}
          </>
        }
      />

      <Card.Body className="p-0 llm-chat-body d-flex flex-column llm-iv-body">
        <div
          ref={transcriptRef}
          className="flex-grow-1 overflow-auto p-3 llm-iv-transcript llm-slim-scroll"
          style={palette}
          role="log"
          aria-live="polite"
          aria-relevant="additions"
        >
          {opening && messages.length === 0 ? (
            <div className="llm-iv-center text-muted">
              <Spinner animation="border" size="sm" className="mr-2" />
              {labels.thinking}
            </div>
          ) : (
            <div className="message-stack llm-iv-stack">
              {messages.map((m) => (
                <Bubble key={m.id} message={m} avatarConfig={avatarConfig} />
              ))}
              {pendingText !== null ? (
                <Bubble
                  key="pending-user"
                  message={{ id: 'pending-user', role: 'user', content: pendingText }}
                  avatarConfig={avatarConfig}
                />
              ) : null}
              {thinking ? (
                <div className="message-wrapper assistant llm-iv-msg llm-iv-thinking">
                  <BubbleAvatar isUser={false} config={avatarConfig} />
                  <div className="message-bubble">
                    <div className="d-flex align-items-center">
                      <div className="thinking-dots mr-2">
                        <span className="dot" />
                        <span className="dot" />
                        <span className="dot" />
                      </div>
                      <span className="small llm-iv-thinking-text">{labels.thinking}</span>
                    </div>
                  </div>
                </div>
              ) : null}
            </div>
          )}
        </div>
      </Card.Body>

      {notice ? (
        <div
          className={`alert alert-${notice.tone === 'info' ? 'info' : 'danger'}${
            notice.markdown ? '' : ' alert-dismissible'
          } llm-iv-notice mb-0`}
          role="alert"
        >
          {notice.markdown ? (
            <div className="llm-iv-notice-md">
              <MarkdownRenderer content={notice.message} />
            </div>
          ) : (
            <span>{notice.message}</span>
          )}
          {notice.retry ? (
            <button
              type="button"
              className={`btn btn-sm btn-outline-${notice.tone === 'info' ? 'info' : 'danger'} ml-2`}
              onClick={notice.retry}
            >
              {labels.retry}
            </button>
          ) : null}
          {!notice.markdown ? (
            <button type="button" className="close" aria-label={labels.close} onClick={onDismissNotice}>
              <span aria-hidden="true">&times;</span>
            </button>
          ) : null}
        </div>
      ) : null}

      {!hideComposer ? (
        <Card.Footer className="bg-white border-0 p-3 llm-chat-footer llm-iv-footer">
          {canApprove ? (
            <div className="llm-iv-approvebar" role="status">
              <span className="llm-iv-approvebar-text">
                <i className="fas fa-check-circle mr-2" aria-hidden="true" />
                {labels.readyToSave}
              </span>
              <button
                type="button"
                className="btn btn-success btn-sm llm-iv-approvebar-btn"
                onClick={onApprove}
                disabled={sendBlocked}
              >
                {approving ? <Spinner animation="border" size="sm" /> : config.labels.approve}
              </button>
            </div>
          ) : null}

          {quickReplies.length > 0 ? (
            <div
              className="suggestion-buttons d-flex flex-wrap llm-iv-quick llm-slim-scroll"
              role="group"
              aria-label={labels.choose}
            >
              {quickReplies.map((reply) => (
                <button
                  key={reply}
                  type="button"
                  className="btn btn-outline-primary btn-sm suggestion-btn llm-iv-quick-btn"
                  onClick={() => submit(reply)}
                >
                  {reply}
                </button>
              ))}
            </div>
          ) : null}

          <MessageInput
            ref={inputRef}
            onSend={submit}
            selectedFiles={noFiles}
            onFilesChange={ignoreFiles}
            disabled={!state}
            sendDisabled={sendBlocked}
            config={inputConfig}
            focusSignal={`${state?.conversation_id ?? 0}:${messages.length}:${thinking ? 1 : 0}`}
            autoFocus
            speech={speech}
            micLabels={micLabels}
          />
        </Card.Footer>
      ) : null}
    </Card>
  );
};

export default InterviewChat;
