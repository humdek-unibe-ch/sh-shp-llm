/**
 * Floating panel layout (size + position)
 * =======================================
 *
 * Remembers the user's preferred floating chat panel size and position in
 * localStorage. Stores only geometry (UI preference) — no personal data,
 * no consent banner.
 *
 * @module hooks/useFloatingPanelSize
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FloatingButtonPosition } from '../types';

export interface PanelSize {
  width: number;
  height: number;
}

export interface PanelLayout extends PanelSize {
  left: number;
  top: number;
}

/** Matches FloatingChat.css defaults for .llm-float-panel */
export const DEFAULT_PANEL_SIZE: PanelSize = {
  width: 380,
  height: 700,
};

const MIN_WIDTH = 320;
const MIN_HEIGHT = 360;
const MAX_WIDTH_RATIO = 0.95;
const MAX_HEIGHT_RATIO = 0.95;
const EDGE_MARGIN = 8;
const STORAGE_PREFIX = 'llm-float-panel-layout';
/** Legacy key from the first resize-only implementation */
const LEGACY_STORAGE_PREFIX = 'llm-float-panel-size';

export type ResizeEdge = 'n' | 's' | 'e' | 'w' | 'ne' | 'nw' | 'se' | 'sw';

/** All edges — used when the panel is freely positioned (resize + move). */
export const ALL_RESIZE_EDGES: ResizeEdge[] = [
  'n',
  's',
  'e',
  'w',
  'ne',
  'nw',
  'se',
  'sw',
];

function storageKey(sectionId?: number): string {
  return `${STORAGE_PREFIX}-${sectionId ?? 'default'}`;
}

function legacyStorageKey(sectionId?: number): string {
  return `${LEGACY_STORAGE_PREFIX}-${sectionId ?? 'default'}`;
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

function getViewportLimits(): { maxWidth: number; maxHeight: number; vw: number; vh: number } {
  if (typeof window === 'undefined') {
    return { maxWidth: 900, maxHeight: 900, vw: 1200, vh: 800 };
  }
  return {
    maxWidth: Math.floor(window.innerWidth * MAX_WIDTH_RATIO),
    maxHeight: Math.floor(window.innerHeight * MAX_HEIGHT_RATIO),
    vw: window.innerWidth,
    vh: window.innerHeight,
  };
}

function clampSize(size: PanelSize): PanelSize {
  const { maxWidth, maxHeight } = getViewportLimits();
  return {
    width: clamp(size.width, MIN_WIDTH, maxWidth),
    height: clamp(size.height, MIN_HEIGHT, maxHeight),
  };
}

function clampLayout(layout: PanelLayout): PanelLayout {
  const size = clampSize(layout);
  const { vw, vh } = getViewportLimits();
  const maxLeft = Math.max(EDGE_MARGIN, vw - size.width - EDGE_MARGIN);
  const maxTop = Math.max(EDGE_MARGIN, vh - size.height - EDGE_MARGIN);
  return {
    ...size,
    left: clamp(layout.left, EDGE_MARGIN, maxLeft),
    top: clamp(layout.top, EDGE_MARGIN, maxTop),
  };
}

/**
 * Default height follows the CSS: min(700, 100vh - 80).
 */
export function getDefaultPanelSize(): PanelSize {
  if (typeof window === 'undefined') {
    return { ...DEFAULT_PANEL_SIZE };
  }
  const height = Math.min(DEFAULT_PANEL_SIZE.height, window.innerHeight - 80);
  return clampSize({ width: DEFAULT_PANEL_SIZE.width, height });
}

/**
 * Default left/top matching FloatingChat.css panel position classes
 * (top: 40px, side inset 24px, center = 50%).
 */
export function getDefaultPanelLayout(position: FloatingButtonPosition): PanelLayout {
  const size = getDefaultPanelSize();
  const { vw } = getViewportLimits();
  const top = 40;
  const inset = 24;

  let left: number;
  switch (position) {
    case 'bottom-left':
    case 'top-left':
      left = inset;
      break;
    case 'bottom-center':
    case 'top-center':
      left = Math.round((vw - size.width) / 2);
      break;
    case 'bottom-right':
    case 'top-right':
    default:
      left = vw - size.width - inset;
      break;
  }

  return clampLayout({ ...size, left, top });
}

function isValidLayout(value: unknown): value is PanelLayout {
  if (!value || typeof value !== 'object') {
    return false;
  }
  const layout = value as Partial<PanelLayout>;
  return (
    typeof layout.width === 'number' &&
    typeof layout.height === 'number' &&
    typeof layout.left === 'number' &&
    typeof layout.top === 'number' &&
    Number.isFinite(layout.width) &&
    Number.isFinite(layout.height) &&
    Number.isFinite(layout.left) &&
    Number.isFinite(layout.top)
  );
}

function readStoredLayout(
  sectionId: number | undefined,
  position: FloatingButtonPosition
): PanelLayout | null {
  if (typeof window === 'undefined') {
    return null;
  }
  try {
    const raw = window.localStorage.getItem(storageKey(sectionId));
    if (raw) {
      const parsed = JSON.parse(raw) as unknown;
      if (isValidLayout(parsed)) {
        return clampLayout(parsed);
      }
    }

    // Migrate legacy size-only storage into a default-anchored layout.
    const legacyRaw = window.localStorage.getItem(legacyStorageKey(sectionId));
    if (legacyRaw) {
      const parsed = JSON.parse(legacyRaw) as Partial<PanelSize>;
      if (
        typeof parsed.width === 'number' &&
        typeof parsed.height === 'number' &&
        Number.isFinite(parsed.width) &&
        Number.isFinite(parsed.height)
      ) {
        const base = getDefaultPanelLayout(position);
        return clampLayout({
          ...base,
          width: parsed.width,
          height: parsed.height,
        });
      }
    }
  } catch {
    return null;
  }
  return null;
}

function writeStoredLayout(sectionId: number | undefined, layout: PanelLayout): void {
  if (typeof window === 'undefined') {
    return;
  }
  try {
    window.localStorage.setItem(storageKey(sectionId), JSON.stringify(clampLayout(layout)));
    window.localStorage.removeItem(legacyStorageKey(sectionId));
  } catch {
    // Quota / private mode — ignore; layout still works for the session.
  }
}

function clearStoredLayout(sectionId?: number): void {
  if (typeof window === 'undefined') {
    return;
  }
  try {
    window.localStorage.removeItem(storageKey(sectionId));
    window.localStorage.removeItem(legacyStorageKey(sectionId));
  } catch {
    // ignore
  }
}

/**
 * Apply a pointer delta to layout for a given resize edge.
 * Opposite edges stay fixed in the viewport.
 */
export function applyResizeDelta(
  start: PanelLayout,
  edge: ResizeEdge,
  dx: number,
  dy: number
): PanelLayout {
  let { width, height, left, top } = start;

  if (edge.includes('e')) {
    width = start.width + dx;
  }
  if (edge.includes('w')) {
    width = start.width - dx;
    left = start.left + dx;
  }
  if (edge.includes('s')) {
    height = start.height + dy;
  }
  if (edge.includes('n')) {
    height = start.height - dy;
    top = start.top + dy;
  }

  return clampLayout({ width, height, left, top });
}

export function applyMoveDelta(start: PanelLayout, dx: number, dy: number): PanelLayout {
  return clampLayout({
    ...start,
    left: start.left + dx,
    top: start.top + dy,
  });
}

export interface UseFloatingPanelSizeResult {
  layout: PanelLayout;
  isCustomLayout: boolean;
  isInteracting: boolean;
  setLayoutFromDrag: (next: PanelLayout) => void;
  beginInteract: () => void;
  endInteract: () => void;
  restoreDefault: () => void;
  edges: ResizeEdge[];
}

/**
 * Manage floating panel layout with localStorage persistence per section.
 */
export function useFloatingPanelSize(
  sectionId: number | undefined,
  position: FloatingButtonPosition,
  enabled: boolean
): UseFloatingPanelSizeResult {
  const edges = useMemo(() => (enabled ? ALL_RESIZE_EDGES : []), [enabled]);

  const [layout, setLayout] = useState<PanelLayout>(() => {
    if (!enabled) {
      return getDefaultPanelLayout(position);
    }
    return readStoredLayout(sectionId, position) ?? getDefaultPanelLayout(position);
  });
  const [isCustomLayout, setIsCustomLayout] = useState<boolean>(() => {
    if (!enabled) {
      return false;
    }
    return readStoredLayout(sectionId, position) !== null;
  });
  const [isInteracting, setIsInteracting] = useState(false);

  // Re-clamp when the viewport changes so the panel never overflows.
  useEffect(() => {
    if (!enabled) {
      return;
    }
    const onResize = () => {
      setLayout((prev) => clampLayout(prev));
    };
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, [enabled]);

  // If CMS toggle turns on later, load stored / default layout.
  useEffect(() => {
    if (!enabled) {
      setIsCustomLayout(false);
      setLayout(getDefaultPanelLayout(position));
      return;
    }
    const stored = readStoredLayout(sectionId, position);
    if (stored) {
      setLayout(stored);
      setIsCustomLayout(true);
    } else {
      setLayout(getDefaultPanelLayout(position));
      setIsCustomLayout(false);
    }
  }, [enabled, sectionId, position]);

  const setLayoutFromDrag = useCallback(
    (next: PanelLayout) => {
      if (!enabled) {
        return;
      }
      const clamped = clampLayout(next);
      setLayout(clamped);
      setIsCustomLayout(true);
      writeStoredLayout(sectionId, clamped);
    },
    [enabled, sectionId]
  );

  const beginInteract = useCallback(() => {
    if (enabled) {
      setIsInteracting(true);
    }
  }, [enabled]);

  const endInteract = useCallback(() => {
    setIsInteracting(false);
  }, []);

  const restoreDefault = useCallback(() => {
    const defaults = getDefaultPanelLayout(position);
    setLayout(defaults);
    setIsCustomLayout(false);
    clearStoredLayout(sectionId);
  }, [sectionId, position]);

  return {
    layout,
    isCustomLayout,
    isInteracting,
    setLayoutFromDrag,
    beginInteract,
    endInteract,
    restoreDefault,
    edges,
  };
}

export { MIN_WIDTH, MIN_HEIGHT, clampLayout, clampSize };
