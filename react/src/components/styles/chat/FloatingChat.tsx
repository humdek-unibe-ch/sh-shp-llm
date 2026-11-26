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
 * When CMS `allow_floating_chat_resize` is on (desktop), the panel can be
 * moved (drag header) and resized from any edge/corner; layout is stored
 * in localStorage per section.
 * 
 * @module components/FloatingChat
 */

import React, { useState, useCallback, useEffect, useRef, useMemo } from 'react';
import { LlmChat } from './LlmChat';
import { ChatPanelHeader, HeaderButton } from '../shared/ChatPanelHeader';
import type { LlmChatConfig, FloatingButtonPosition } from '../../../types';
import {
  useFloatingPanelSize,
  applyResizeDelta,
  applyMoveDelta,
  type ResizeEdge,
  type PanelLayout,
} from '../../../hooks/useFloatingPanelSize';
import './FloatingChat.css';

/** Desktop breakpoint matching FloatingChat.css (full-screen panel below this). */
const FLOAT_RESIZE_MIN_WIDTH = 577;

/**
 * Shortcut request: open the panel and prefill the composer (user edits/sends).
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

type InteractMode = 'resize' | 'move';

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

function cursorForEdge(edge: ResizeEdge): string {
  switch (edge) {
    case 'n':
    case 's':
      return 'ns-resize';
    case 'e':
    case 'w':
      return 'ew-resize';
    case 'ne':
    case 'sw':
      return 'nesw-resize';
    case 'nw':
    case 'se':
      return 'nwse-resize';
    default:
      return 'nwse-resize';
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
  const [isDesktop, setIsDesktop] = useState(() =>
    typeof window !== 'undefined' ? window.innerWidth >= FLOAT_RESIZE_MIN_WIDTH : true
  );
  const panelRef = useRef<HTMLDivElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const shortcutTrayTimeoutRef = useRef<number | null>(null);
  const interactSessionRef = useRef<{
    mode: InteractMode;
    edge?: ResizeEdge;
    startX: number;
    startY: number;
    startLayout: PanelLayout;
  } | null>(null);

  // Generate unique IDs based on section_id for multiple floating buttons
  const uniqueId = useMemo(() => `llm-float-${config.sectionId || 'default'}`, [config.sectionId]);

  // CMS field floating_shortcuts_hide_delay (seconds, default 2) → ms for setTimeout
  const shortcutTrayHideDelayMs = useMemo(() => {
    const seconds = Number(config.floatingShortcutsHideDelay);
    const safe = Number.isFinite(seconds) && seconds >= 0 ? seconds : 2;
    return Math.min(safe, 60) * 1000;
  }, [config.floatingShortcutsHideDelay]);
  
  // Calculate z-index offset for stacking multiple buttons
  const zIndexOffset = useMemo(
    () => getZIndexOffset(config.floatingButtonPosition, config.sectionId),
    [config.floatingButtonPosition, config.sectionId]
  );

  const layoutControlsEnabled = Boolean(config.allowFloatingChatResize) && isDesktop;
  const {
    layout: panelLayout,
    isCustomLayout,
    isInteracting,
    setLayoutFromDrag,
    beginInteract,
    endInteract,
    restoreDefault,
    edges: resizeEdges,
  } = useFloatingPanelSize(
    config.sectionId,
    config.floatingButtonPosition,
    layoutControlsEnabled
  );

  useEffect(() => {
    const onViewportChange = () => {
      setIsDesktop(window.innerWidth >= FLOAT_RESIZE_MIN_WIDTH);
    };
    window.addEventListener('resize', onViewportChange);
    return () => window.removeEventListener('resize', onViewportChange);
  }, []);

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

  // Handle shortcut click — open chat and prefill the composer (do not auto-send)
  const handleShortcutClick = useCallback((message: string) => {
    // Clear any pending timeout since we're closing the tray immediately
    if (shortcutTrayTimeoutRef.current) {
      clearTimeout(shortcutTrayTimeoutRef.current);
      shortcutTrayTimeoutRef.current = null;
    }
    const requestId = `shortcut-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    setPendingShortcutRequest({ id: requestId, message });
    setIsOpen(true);
    setShowShortcutTray(false);
  }, []);

  const startInteract = useCallback(
    (mode: InteractMode, event: React.PointerEvent, edge?: ResizeEdge) => {
      if (!layoutControlsEnabled) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      interactSessionRef.current = {
        mode,
        edge,
        startX: event.clientX,
        startY: event.clientY,
        startLayout: { ...panelLayout },
      };
      document.body.style.cursor = mode === 'move' ? 'move' : cursorForEdge(edge || 'se');
      document.body.style.userSelect = 'none';
      beginInteract();
      (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
    },
    [layoutControlsEnabled, panelLayout, beginInteract]
  );

  const handleResizePointerDown = useCallback(
    (edge: ResizeEdge, event: React.PointerEvent<HTMLDivElement>) => {
      startInteract('resize', event, edge);
    },
    [startInteract]
  );

  const handleMovePointerDown = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      // Ignore drags that start on header action buttons
      const target = event.target as HTMLElement | null;
      if (target?.closest('button, a, input, textarea, select')) {
        return;
      }
      startInteract('move', event);
    },
    [startInteract]
  );

  useEffect(() => {
    if (!isInteracting) {
      return;
    }

    const onPointerMove = (event: PointerEvent) => {
      const session = interactSessionRef.current;
      if (!session) {
        return;
      }
      const dx = event.clientX - session.startX;
      const dy = event.clientY - session.startY;
      if (session.mode === 'move') {
        setLayoutFromDrag(applyMoveDelta(session.startLayout, dx, dy));
      } else if (session.edge) {
        setLayoutFromDrag(applyResizeDelta(session.startLayout, session.edge, dx, dy));
      }
    };

    const finishInteract = () => {
      interactSessionRef.current = null;
      document.body.style.cursor = '';
      document.body.style.userSelect = '';
      endInteract();
    };

    document.addEventListener('pointermove', onPointerMove);
    document.addEventListener('pointerup', finishInteract);
    document.addEventListener('pointercancel', finishInteract);
    return () => {
      document.removeEventListener('pointermove', onPointerMove);
      document.removeEventListener('pointerup', finishInteract);
      document.removeEventListener('pointercancel', finishInteract);
      document.body.style.cursor = '';
      document.body.style.userSelect = '';
    };
  }, [isInteracting, setLayoutFromDrag, endInteract]);

  // Handle escape key to close panel
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isOpen) {
        if (isInteracting) {
          interactSessionRef.current = null;
          endInteract();
          return;
        }
        handleClose();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, isInteracting, handleClose, endInteract]);

  // Cleanup timeout on unmount
  useEffect(() => {
    return () => {
      if (shortcutTrayTimeoutRef.current) {
        clearTimeout(shortcutTrayTimeoutRef.current);
      }
    };
  }, []);

  // Handle click outside to close panel (skip while resizing/moving)
  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (isInteracting) {
        return;
      }
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
  }, [isOpen, isInteracting, handleClose]);

  // The icon field holds either an image URL (resolved server-side) or a FontAwesome class
  const iconImage = /\/|\.(png|jpe?g|gif|svg|webp)$/i.test(config.floatingButtonIcon)
    ? config.floatingButtonIcon
    : undefined;
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

  const panelStyle = useMemo(() => {
    const style: React.CSSProperties = {
      zIndex: 1051 + zIndexOffset,
    };
    if (layoutControlsEnabled) {
      style.width = panelLayout.width;
      style.height = panelLayout.height;
      style.left = panelLayout.left;
      style.top = panelLayout.top;
      style.right = 'auto';
      style.bottom = 'auto';
      style.transform = 'none';
      style.maxHeight = 'none';
      style.minHeight = 0;
    }
    return style;
  }, [zIndexOffset, layoutControlsEnabled, panelLayout]);

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
          // Delay hiding the tray so users can click shortcuts (CMS: floating_shortcuts_hide_delay)
          shortcutTrayTimeoutRef.current = window.setTimeout(() => {
            setShowShortcutTray(false);
            shortcutTrayTimeoutRef.current = null;
          }, shortcutTrayHideDelayMs);
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
            }, shortcutTrayHideDelayMs);
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
            {iconImage ? (
              <img src={iconImage} alt="" className="llm-float-btn-img" />
            ) : (
              <i className={iconClass}></i>
            )}
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
          className={`llm-float-panel card shadow-lg ${layoutControlsEnabled ? 'llm-float-panel-free' : panelPositionClass}${isInteracting ? ' llm-float-panel-resizing' : ''}${isCustomLayout ? ' llm-float-panel-custom-size' : ''}`}
          role="dialog"
          aria-labelledby={`${uniqueId}-title`}
          style={panelStyle}
        >
          {/* Panel Header (shared with the inline chat and the form interview) */}
          <ChatPanelHeader
            title={config.floatingChatTitle}
            titleId={`${uniqueId}-title`}
            iconClass={iconClass}
            iconImage={iconImage}
            status={config.showModelInfo ? config.configuredModel : undefined}
            statusIcon="fa-robot"
            className={layoutControlsEnabled ? 'llm-float-panel-header-draggable' : undefined}
            headerProps={
              layoutControlsEnabled
                ? {
                    onPointerDown: handleMovePointerDown,
                    title: 'Drag to move',
                  }
                : undefined
            }
            actions={
              <>
                {layoutControlsEnabled && (
                  <HeaderButton
                    icon="fa-undo"
                    label="Restore default size and position"
                    onClick={restoreDefault}
                    disabled={!isCustomLayout}
                  />
                )}
                <HeaderButton icon="fa-minus" label="Minimize chat" onClick={handleClose} />
                <HeaderButton icon="fa-times" label="Close chat" onClick={handleClose} />
              </>
            }
          />

          {/* Panel Body - Chat Interface */}
          <div className="llm-float-panel-body">
            <LlmChat 
              config={llmChatConfig} 
              shortcutRequest={pendingShortcutRequest}
              onShortcutRequestConsumed={handleShortcutRequestConsumed}
            />
          </div>

          {/* Desktop resize handles — all edges when CMS allows resize/move */}
          {layoutControlsEnabled &&
            resizeEdges.map((edge) => (
              <div
                key={edge}
                className={`llm-float-resize-handle llm-float-resize-handle-${edge}`}
                onPointerDown={(event) => handleResizePointerDown(edge, event)}
                role="separator"
                aria-orientation={edge === 'n' || edge === 's' ? 'horizontal' : 'vertical'}
                aria-label={`Resize chat panel (${edge})`}
              />
            ))}
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
