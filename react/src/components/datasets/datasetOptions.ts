/**
 * Static option lists and helpers for dataset UI dropdowns.
 *
 * @module components/datasets/datasetOptions
 */

/** Dropdown options for the dataset type selector. */
export const datasetTypeOptions = [
  { value: 'golden_manual', label: 'golden_manual' },
  { value: 'production_replay', label: 'production_replay' },
  { value: 'pilot_study_replay', label: 'pilot_study_replay' },
  { value: 'conversation_replay', label: 'conversation_replay' },
  { value: 'form_submission_replay', label: 'form_submission_replay' },
  { value: 'script_fixture', label: 'script_fixture' },
];

/** executionProfileOptions constant or utility. */
export const executionProfileOptions = [
  { value: 'chat_runtime', label: 'chat_runtime' },
  { value: 'form_runtime', label: 'form_runtime' },
  { value: 'script_runtime', label: 'script_runtime' },
  { value: 'text_only', label: 'text_only' },
];

/** describeExecutionProfile function. */
export function describeExecutionProfile(profile?: string | null): string {
  switch (profile) {
    case 'chat_runtime':
      return 'Replay/evaluate chat-style prompts with message history.';
    case 'form_runtime':
      return 'Replay/evaluate structured form submissions.';
    case 'script_runtime':
      return 'Replay/evaluate script prompts and fixtures.';
    case 'text_only':
      return 'Replay/evaluate free-form text payloads.';
    default:
      return 'Runtime execution profile used for replay and evaluation.';
  }
}
