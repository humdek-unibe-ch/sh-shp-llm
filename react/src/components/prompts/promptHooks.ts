/**
 * Prompt Lab React hooks.
 *
 * Provides `usePromptBootstrap` for loading and refreshing Prompt Lab
 * bootstrap data (versions, variables, profiles, datasets) from the
 * PHP backend. Handles auto-reload on descriptor changes and exposes
 * a manual refresh trigger.
 *
 * @module components/prompts/promptHooks
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { createPromptLabApi } from './promptApi';
import type { PromptBootstrapData, PromptDescriptor } from './promptTypes';

/** Options for the usePromptBootstrap hook. */
interface UsePromptBootstrapOptions {
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  currentContent: string;
  currentMeta: string;
  runtimeOverrides?: Record<string, unknown>;
  enabled?: boolean;
}

/**
 * Loads Prompt Lab bootstrap data and auto-refreshes on descriptor changes.
 *
 * Returns the current bootstrap payload, loading/error state, and a
 * manual `refresh()` trigger. Skips auto-reload immediately after a
 * local update to avoid overwriting optimistic state.
 */
export function usePromptBootstrap({
  api,
  descriptor,
  currentContent,
  currentMeta,
  runtimeOverrides,
  enabled = true,
}: UsePromptBootstrapOptions) {
  const latestStateRef = useRef({
    currentContent,
    currentMeta,
    runtimeOverrides,
  });
  const descriptorRef = useRef(descriptor);
  const [bootstrap, setBootstrapRaw] = useState<PromptBootstrapData | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const skipNextAutoReloadRef = useRef(false);

  const setBootstrap = useCallback((next: PromptBootstrapData | null) => {
    skipNextAutoReloadRef.current = true;
    setBootstrapRaw(next);
  }, []);

  useEffect(() => {
    latestStateRef.current = {
      currentContent,
      currentMeta,
      runtimeOverrides,
    };
  }, [currentContent, currentMeta, runtimeOverrides]);

  useEffect(() => {
    descriptorRef.current = descriptor;
  }, [descriptor]);

  const reload = useCallback(async () => {
    const hasOwnerIdentity = descriptor.ownerId > 0;
    if (!enabled || !hasOwnerIdentity) {
      setBootstrapRaw(null);
      return null;
    }

    setLoading(true);
    setError(null);
    try {
      const next = await api.bootstrapOwner(
        descriptorRef.current,
        latestStateRef.current.currentContent,
        latestStateRef.current.currentMeta,
        latestStateRef.current.runtimeOverrides,
      );
      setBootstrapRaw(next);
      return next;
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Failed to load prompt state';
      setError(message);
      throw err;
    } finally {
      setLoading(false);
    }
  }, [api, enabled]);

  useEffect(() => {
    if (!enabled) {
      return;
    }

    if (skipNextAutoReloadRef.current) {
      skipNextAutoReloadRef.current = false;
      return;
    }

    reload().catch(() => undefined);
  }, [
    descriptor.languageId,
    descriptor.ownerId,
    descriptor.ownerType,
    descriptor.pageId,
    descriptor.promptSlot,
    enabled,
    reload,
  ]);

  return {
    bootstrap,
    loading,
    error,
    setBootstrap,
    reload,
  };
}
