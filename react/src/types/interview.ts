/**
 * Interview types
 * ===============
 *
 * Types for the llmFormLogList interview mount. They mirror the
 * participant-safe projection produced by LlmFormInterviewService on
 * the server - no raw responses, sent context or private inactive
 * values ever reach this surface.
 */

import type { ChatAppearance } from './index';

/** One field definition as projected by the server. */
export interface InterviewFieldOption {
  value: string;
  label: string;
}

export interface InterviewFieldDef {
  name: string;
  label: string;
  style: string;
  input_type: string;
  required: boolean;
  options: InterviewFieldOption[] | null;
  constraints: Record<string, unknown> | null;
  interview: boolean;
  native_only: boolean;
  manual_writable: boolean;
  llm_writable: boolean;
  hidden?: boolean;
}

/** One progress step status from the deterministic server progress. */
export interface InterviewProgressField {
  label: string;
  status: 'done' | 'pending' | 'active';
  required: boolean;
}

export interface InterviewProgress {
  fields: Record<string, InterviewProgressField>;
  total: number;
  done: number;
  required_total: number;
  required_done: number;
  active: string | null;
  ready_for_approval: boolean;
}

/** Participant-visible transcript entry. */
export interface InterviewMessage {
  id: number | null;
  role: 'user' | 'assistant' | 'system';
  content: string;
  timestamp: string | null;
}

/** Server projection of the scoped conversation state. */
export interface InterviewState {
  conversation_id: number;
  record_id: number | null;
  revision: number;
  phase: string;
  draft: Record<string, unknown>;
  fields: Record<string, InterviewFieldDef>;
  native_html: Record<string, string>;
  progress: InterviewProgress;
  /** The field the coach is asking about right now (also an answered one while editing). */
  asking?: string | null;
  messages: InterviewMessage[];
  completed_at: string | null;
  /** 'edit' = a saved record is being revised; 'new' = a create draft. */
  mode: 'new' | 'edit';
  /** Names of the answers that differ from the saved record (edit mode). */
  changed: string[];
  /** What the saved record holds for each changed answer (edit mode). */
  baseline: Record<string, unknown>;
  can_approve: boolean;
  needs_start: boolean;
  /** Quick-reply choices the coach offered for the pending question. */
  suggestions?: string[];
}

/** Chat panel placement relative to the form area. */
export type ChatPosition = 'right' | 'left' | 'top' | 'bottom';

/** What opening the interview does with an older draft (CMS: chat retention). */
export type InterviewRetention = 'continue' | 'new_on_open' | 'new_after_save';

/**
 * When pressing the approve button first asks for a confirmation:
 * `edit` only for a revised entry (what changed), `always` also for a new
 * entry (the record that will be saved), `never` saves at once.
 */
export type InterviewConfirmMode = 'edit' | 'always' | 'never';

/** Participant-facing UI strings (built-in defaults + `llm_interview_labels`). */
export type InterviewUiLabels = Record<string, string>;

/** Confirmation dialog: the switch (`mode`) plus the texts of the core confirmation fields. */
export interface InterviewConfirmConfig {
  mode: InterviewConfirmMode;
  title: string;
  message: string;
  continue: string;
  cancel: string;
}

/** data-config payload emitted by LlmFormLogListView. */
export interface InterviewConfig {
  sectionId: number;
  recordId: number | null;
  /** The URL (or a hidden list) opens the workspace on load. */
  autoOpen: boolean;
  /** Real modal window over the visible list (CMS: show_form_in_modal). */
  modal: boolean;
  /** The section shows a list at all (CMS: show list). */
  showList: boolean;
  /** The list is part of this page render (false while a record is deep-linked in page mode). */
  listRendered: boolean;
  formName: string;
  urls: {
    new: string;
    list: string;
  };
  retention: InterviewRetention;
  confirm: InterviewConfirmConfig;
  csrfToken: string;
  chatPosition: ChatPosition;
  progressLayout: 'stepper' | 'cards' | 'list' | 'none';
  allowManualEdit: boolean;
  labels: {
    title: string;
    subtitle: string;
    inputPlaceholder: string;
    send: string;
    approve: string;
    /** Start button label (the section's `label_new`). */
    start: string;
    /** Edit action label (the section's `label_edit`). */
    edit: string;
    ui: InterviewUiLabels;
  };
  /** Bubble palette + avatars, same contract as llmChat's `llm_chat_appearance`. */
  appearance: ChatAppearance;
  speech: {
    enabled: boolean;
  };
}

/** Typed interview API error; carries optional server context. */
export class InterviewApiError extends Error {
  readonly type: string;
  readonly status: number;
  readonly fieldErrors: Record<string, string> | null;
  readonly formErrors: Record<string, string> | null;
  readonly state: InterviewState | null;

  constructor(
    type: string,
    message: string,
    status: number,
    extras: {
      fieldErrors?: Record<string, string> | null;
      formErrors?: Record<string, string> | null;
      state?: InterviewState | null;
    } = {}
  ) {
    super(message);
    this.name = 'InterviewApiError';
    this.type = type;
    this.status = status;
    this.fieldErrors = extras.fieldErrors ?? null;
    this.formErrors = extras.formErrors ?? null;
    this.state = extras.state ?? null;
  }
}

/** Receipt returned by a successful approval. */
export interface InterviewApprovalReceipt {
  record_id: number;
  request_token: string;
  approved_at: string;
  redirect?: string;
}
