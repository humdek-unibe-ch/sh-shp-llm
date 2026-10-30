/**
 * Chat Panel Header
 * =================
 *
 * The one header of every chat surface: the floating llmChat panel, the
 * inline llmChat and the form-interview coach all render this component,
 * so they look and behave the same - round avatar, title, a status line
 * (model name or coach status) and the trailing controls.
 *
 * The classes are those of the original floating panel header
 * (`llm-float-panel-*`), so existing themes and the mobile overrides in
 * FloatingChat.css keep working unchanged.
 *
 * @module components/styles/shared/ChatPanelHeader
 */

import React from 'react';
import './ChatPanelHeader.css';

/** FontAwesome class as authored: `fa-flag` or `fas fa-flag`. */
export function faClass(raw: string | undefined, fallback = 'fa-robot'): string {
  const icon = (raw || '').trim() || fallback;
  return /(^|\s)fa[srldb]?(\s|$)/.test(icon) ? icon : `fas ${icon}`;
}

export interface ChatPanelHeaderProps {
  /** Header title (conversation title, coach name, ...). */
  title: string;
  /** Status line next to the green dot, for example the model name. */
  status?: React.ReactNode;
  /** FontAwesome icon shown in front of the status text. */
  statusIcon?: string;
  /** Status tone: the dot pulses while the other side is busy. */
  busy?: boolean;
  /** Mark a degraded status (for example a model mismatch). */
  warning?: boolean;
  /** Avatar FontAwesome class. */
  iconClass?: string;
  /** Avatar image URL; wins over `iconClass`. */
  iconImage?: string;
  /** DOM id of the title (target of `aria-labelledby`). */
  titleId?: string;
  /** Trailing controls - use <HeaderButton>. */
  actions?: React.ReactNode;
  className?: string;
}

export const ChatPanelHeader: React.FC<ChatPanelHeaderProps> = ({
  title,
  status,
  statusIcon,
  busy = false,
  warning = false,
  iconClass = 'fa-robot',
  iconImage,
  titleId,
  actions,
  className = '',
}) => (
  <div
    className={`llm-panel-header llm-float-panel-header card-header bg-primary text-white d-flex align-items-center justify-content-between py-2 px-3 ${className}`.trim()}
  >
    <div className="d-flex align-items-center llm-panel-header-main">
      <div className="llm-float-panel-avatar mr-2">
        {iconImage ? (
          <img src={iconImage} alt="" className="llm-panel-avatar-img" />
        ) : (
          <i className={`${faClass(iconClass)} text-primary`} aria-hidden="true" />
        )}
      </div>
      <div className="llm-panel-header-text">
        <h6 id={titleId} className="mb-0 font-weight-normal text-truncate">
          {title}
        </h6>
        {status ? (
          <small className="d-flex align-items-center">
            <span
              className={`llm-float-status-dot ${warning ? 'bg-warning' : 'bg-success'} mr-1${busy ? ' is-busy' : ''}`}
              aria-hidden="true"
            />
            {statusIcon ? <i className={`${faClass(statusIcon)} fa-xs mr-1`} aria-hidden="true" /> : null}
            <span className="text-truncate">{status}</span>
          </small>
        ) : null}
      </div>
    </div>
    {actions ? <div className="llm-float-panel-actions flex-shrink-0 ml-2">{actions}</div> : null}
  </div>
);

export interface HeaderButtonProps {
  /** FontAwesome icon, e.g. `fa-minus`. */
  icon: string;
  /** Tooltip + accessible name. */
  label: string;
  onClick: () => void;
  disabled?: boolean;
}

/** Round-less white icon button of the header's trailing controls. */
export const HeaderButton: React.FC<HeaderButtonProps> = ({ icon, label, onClick, disabled }) => (
  <button
    type="button"
    className="btn btn-link text-white p-1 ml-1"
    onClick={onClick}
    disabled={disabled}
    aria-label={label}
    title={label}
  >
    <i className={faClass(icon)} aria-hidden="true" />
  </button>
);

export default ChatPanelHeader;
