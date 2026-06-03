/**
 * Floating Chat Component
 * =======================
 * 
 * A floating chat button that opens a chat panel next to it.
 * When enabled, the regular chat interface is replaced with a floating button
 * that can be positioned at different corners of the screen.
 * 
 * Design: Clean side panel that appears adjacent to the floating button,
 * similar to popular chat widgets (Intercom, Drift, etc.)
 * 
 * Uses Bootstrap 4.6 classes for styling and minimal custom CSS.
 * 
 * Supports multiple floating buttons on the same page by using unique IDs
 * based on section_id.
 * 
 * @module components/FloatingChat
 */

import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import { LlmChat } from './LlmChat';
import type { LlmChatConfig, FloatingButtonPosition } from '../../../types';
import './FloatingChat.css';

/**
 * Props for FloatingChat component
 */
/**
 * Shortcut request object for safe "send exactly once" behavior
 */
interface ShortcutRequest {
  id: string;
  message: string;
}

/**
 * Props for FloatingChat component
 */
interface FloatingChatProps {
  /** Component configuration from PHP backend */
  config: LlmChatConfig;
}

/**
 * Get z-index offset based on position to stack buttons properly
 */
function getZIndexOffset(position: FloatingButtonPosition, sectionId?: number): number {
  // Base z-index varies by position to stack buttons at same position
  const positionOrder: Record<FloatingButtonPosition, number> = {
    'bottom-right': 0,
    'bottom-left': 1,
    'top-right': 2,
    'top-left': 3,
    'bottom-center': 4,
    'top-center': 5
  };
  
  // Use section ID to offset buttons at the same position
  const sectionOffset = sectionId ? (sectionId % 10) : 0;
  return positionOrder[position] * 10 + sectionOffset;
}

/**
 * Get position classes for the floating button
 */
function getButtonPositionClasses(position: FloatingButtonPosition): string {
  switch (position) {
    case 'bottom-right':
      return 'llm-float-btn-bottom-right';
    case 'bottom-left':
      return 'llm-float-btn-bottom-left';
    case 'top-right':
      return 'llm-float-btn-top-right';
    case 'top-left':
      return 'llm-float-btn-top-left';
    case 'bottom-center':
      return 'llm-float-btn-bottom-center';
    case 'top-center':
      return 'llm-float-btn-top-center';
    default:
      return 'llm-float-btn-bottom-right';
  }
}

/**
 * Get position classes for the chat panel based on button position
 */
function getPanelPositionClasses(position: FloatingButtonPosition): string {
  switch (position) {
    case 'bottom-right':
      return 'llm-float-panel-bottom-right';
    case 'bottom-left':
      return 'llm-float-panel-bottom-left';
    case 'top-right':
      return 'llm-float-panel-top-right';
    case 'top-left':
      return 'llm-float-panel-top-left';
    case 'bottom-center':
      return 'llm-float-panel-bottom-center';
    case 'top-center':
      return 'llm-float-panel-top-center';
    default:
      return 'llm-float-panel-bottom-right';
  }
}

/**
 * Floating Chat Component
 * 
 * Renders a floating action button that opens a chat panel.
 * The panel appears next to the button for a clean, professional look.
 * 
 * @param props - Component props
 */
export const FloatingChat: React.FC<FloatingChatProps> = ({ config }) => {
  const [isOpen, setIsOpen] = useState(false);
  const [hasUnread, setHasUnread] = useState(false);
  const [showShortcutTray, setShowShortcutTray] = useState(false);
  const [pendingShortcutRequest, setPendingShortcutRequest] = useState<ShortcutRequest | null>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const shortcutTrayTimeoutRef = useRef<number | null>(null);

  // Generate unique IDs based on section_id for multiple floating buttons
  const uniqueId = useMemo(() => `llm-float-${config.sectionId || 'default'}`, [config.sectionId]);
  
  // Calculate z-index offset for stacking multiple buttons
  const zIndexOffset = useMemo(
    () => getZIndexOffset(config.floatingButtonPosition, config.sectionId),
    [config.floatingButtonPosition, config.sectionId]
  );

  // Toggle the chat panel
  const handleToggle = useCallback(() => {
    setIsOpen(prev => !prev);
    if (!isOpen) {
      setHasUnread(false);
    }
  }, [isOpen]);

  // Close the chat panel
  const handleClose = useCallback(() => {
    setIsOpen(false);
    setPendingShortcutRequest(null);
  }, []);

  // Callback to clear pending shortcut request after it's consumed by LlmChat
  const handleShortcutRequestConsumed = useCallback(() => {
    setPendingShortcutRequest(null);
  }, []);

  // Handle shortcut click - generates unique request ID for safe "send exactly once"
  const handleShortcutClick = useCallback((message: string) => {
    // Clear any pending timeout since we're closing the tray immediately
    if (shortcutTrayTimeoutRef.current) {
      clearTimeout(shortcutTrayTimeoutRef.current);
      shortcutTrayTimeoutRef.current = null;
    }
    const requestId = `shortcut-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    setPendingShortcutRequest({ id: requestId, message });
    setIsOpen(true);
  }, []);

  // Handle escape key to close panel
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isOpen) {
        handleClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, handleClose]);

  // Cleanup timeout on unmount
  useEffect(() => {
    return () => {
      if (shortcutTrayTimeoutRef.current) {
        clearTimeout(shortcutTrayTimeoutRef.current);
      }
    };
  }, []);

  // Handle click outside to close panel
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (
        isOpen &&
        panelRef.current &&
        buttonRef.current &&
        !panelRef.current.contains(e.target as Node) &&
        !buttonRef.current.contains(e.target as Node)
      ) {
        handleClose();
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [isOpen, handleClose]);

  // Determine icon class - ensure it has 'fa' or 'fas' prefix
  const iconClass = config.floatingButtonIcon.startsWith('fa-') 
    ? `fas ${config.floatingButtonIcon}` 
    : config.floatingButtonIcon;

  const buttonPositionClass = getButtonPositionClasses(config.floatingButtonPosition);
  const panelPositionClass = getPanelPositionClasses(config.floatingButtonPosition);

  // Calculate position offset for multiple buttons at same position
  const buttonStyle = useMemo(() => ({
    zIndex: 1050 + zIndexOffset
  }), [zIndexOffset]);

  const panelStyle = useMemo(() => ({
    zIndex: 1051 + zIndexOffset
  }), [zIndexOffset]);

  // Memoize the config passed to LlmChat to ensure stability
  const llmChatConfig = useMemo(() => ({
    ...config,
    // Override floating button setting to prevent recursion
    enableFloatingButton: false,
    // Indicate that we're in floating mode
    isFloatingMode: true,
    // Force scroll to bottom when panel opens
    forceScrollToBottom: isOpen
  }), [config, isOpen]);

  // Check if shortcuts are configured
  const hasShortcuts = config.floatingShortcuts && config.floatingShortcuts.length > 0;

  return (
    <div className="llm-floating-chat-wrapper" id={uniqueId} ref={wrapperRef}>
      {/* Floating Action Button and Shortcut Tray Wrapper */}
      <div
        className="llm-float-controls-wrapper"
        onMouseEnter={() => {
          if (shortcutTrayTimeoutRef.current) {
            clearTimeout(shortcutTrayTimeoutRef.current);
            shortcutTrayTimeoutRef.current = null;
          }
          if (hasShortcuts && !isOpen) {
            setShowShortcutTray(true);
          }
        }}
        onMouseLeave={() => {
          // Delay hiding the tray by 5 seconds to allow clicking shortcuts
          shortcutTrayTimeoutRef.current = window.setTimeout(() => {
            setShowShortcutTray(false);
            shortcutTrayTimeoutRef.current = null;
          }, 5000);
        }}
        onFocus={() => {
          if (shortcutTrayTimeoutRef.current) {
            clearTimeout(shortcutTrayTimeoutRef.current);
            shortcutTrayTimeoutRef.current = null;
          }
          if (hasShortcuts && !isOpen) {
            setShowShortcutTray(true);
          }
        }}
        onBlur={(e) => {
          // Only hide tray if focus leaves the entire wrapper
          if (!wrapperRef.current?.contains(e.relatedTarget as Node)) {
            shortcutTrayTimeoutRef.current = window.setTimeout(() => {
              setShowShortcutTray(false);
              shortcutTrayTimeoutRef.current = null;
            }, 5000);
          }
        }}
      >
        {/* Floating Action Button */}
        <button
          ref={buttonRef}
          type="button"
          className={`llm-float-btn btn btn-primary shadow ${buttonPositionClass} ${isOpen ? 'llm-float-btn-active' : ''} ${config.floatingButtonLabel && config.floatingButtonLabel.trim() !== '' ? 'llm-float-btn-with-label' : ''}`}
          onClick={handleToggle}
          aria-label={isOpen ? 'Close chat' : (config.floatingButtonLabel || 'Open chat')}
          title={isOpen ? 'Close chat' : (config.floatingButtonLabel || 'Open chat')}
          aria-expanded={isOpen}
          aria-controls={`${uniqueId}-panel`}
          style={buttonStyle}
        >
        {isOpen ? (
          <i className="fas fa-times"></i>
        ) : (
          <>
            <i className={iconClass}></i>
            {config.floatingButtonLabel && config.floatingButtonLabel.trim() !== '' && (
              <span className="llm-float-btn-label ml-2 d-none d-md-inline">{config.floatingButtonLabel}</span>
            )}
          </>
        )}
          {!isOpen && hasUnread && (
            <span className="llm-float-btn-badge badge badge-danger">!</span>
          )}
        </button>

        {/* Shortcut Tray - shown on hover/focus when panel is closed */}
        {hasShortcuts && !isOpen && showShortcutTray && (
          <div className={`llm-shortcut-tray llm-shortcut-tray-${config.floatingButtonPosition}`}>
            {config.floatingShortcuts!.map((shortcut, index) => (
              <button
                key={index}
                type="button"
                className="llm-shortcut-pill btn btn-sm btn-light"
                onClick={() => handleShortcutClick(shortcut.message)}
                title={shortcut.label}
              >
                {shortcut.label}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Chat Panel */}
      {isOpen && (
        <div 
          ref={panelRef}
          id={`${uniqueId}-panel`}
          className={`llm-float-panel card shadow-lg ${panelPositionClass}`}
          role="dialog"
          aria-labelledby={`${uniqueId}-title`}
          style={panelStyle}
        >
          {/* Panel Header */}
          <div className="llm-float-panel-header card-header bg-primary text-white d-flex align-items-center justify-content-between py-2 px-3">
            <div className="d-flex align-items-center">
              <div className="llm-float-panel-avatar mr-2">
                <i className={`${iconClass} text-primary`}></i>
              </div>
              <div>
                <h6 id={`${uniqueId}-title`} className="mb-0 font-weight-normal">
                  {config.floatingChatTitle}
                </h6>
                <small className="d-flex align-items-center">
                  <span className="llm-float-status-dot bg-success mr-1"></span>
                  <i className="fas fa-robot fa-xs mr-1"></i>
                  {config.configuredModel}
                </small>
              </div>
            </div>
            <div className="llm-float-panel-actions">
              <button
                type="button"
                className="btn btn-link text-white p-1"
                onClick={handleClose}
                aria-label="Minimize chat"
                title="Minimize"
              >
                <i className="fas fa-minus"></i>
              </button>
              <button
                type="button"
                className="btn btn-link text-white p-1 ml-1"
                onClick={handleClose}
                aria-label="Close chat"
                title="Close"
              >
                <i className="fas fa-times"></i>
              </button>
            </div>
          </div>

          {/* Panel Body - Chat Interface */}
          <div className="llm-float-panel-body">
            <LlmChat 
              config={llmChatConfig} 
              shortcutRequest={pendingShortcutRequest}
              onShortcutRequestConsumed={handleShortcutRequestConsumed}
            />
          </div>
        </div>
      )}

      {/* Backdrop for mobile */}
      {isOpen && (
        <div 
          className="llm-float-backdrop d-md-none" 
          onClick={handleClose}
          aria-hidden="true"
        />
      )}
    </div>
  );
};

/** Default export for this module. */
export default FloatingChat;
