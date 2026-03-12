import { useCallback, useEffect, useRef, useState } from 'react';
import type { createPromptLabApi } from './promptApi';
import type { PromptBootstrapData, PromptDescriptor } from './promptTypes';

interface UsePromptBootstrapOptions {
  api: ReturnType<typeof createPromptLabApi>;
  descriptor: PromptDescriptor;
  currentContent: string;
  currentMeta: string;
  runtimeOverrides?: Record<string, unknown>;
  enabled?: boolean;
}

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
  const [bootstrap, setBootstrap] = useState<PromptBootstrapData | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
    if (!enabled || !descriptor.ownerId) {
      setBootstrap(null);
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
      setBootstrap(next);
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
