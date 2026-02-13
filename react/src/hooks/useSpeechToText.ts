/**
 * useSpeechToText Hook
 * ====================
 * 
 * Encapsulates all speech-to-text recording logic:
 * - Microphone permission request
 * - MediaRecorder management
 * - Auto-stop after max duration
 * - Audio blob processing & server transcription
 * - Graceful error handling (permission denied, etc.)
 * 
 * Extracted from MessageInput.tsx for modularity.
 * 
 * @module hooks/useSpeechToText
 */

import { useState, useRef, useCallback, useEffect } from 'react';

/** Maximum recording duration (seconds) to prevent payload-too-large errors */
const MAX_RECORDING_DURATION_MS = 60_000;

interface UseSpeechToTextOptions {
  /** Whether speech-to-text is enabled in config */
  enabled: boolean;
  /** The speech-to-text model identifier */
  model: string;
  /** Section ID for the API request */
  sectionId?: number;
  /** Callback that receives transcribed text */
  onTranscription: (text: string) => void;
}

interface UseSpeechToTextReturn {
  /** Whether the browser supports speech-to-text */
  isAvailable: boolean;
  /** Whether we are currently recording */
  isRecording: boolean;
  /** Whether the audio is being processed by the server */
  isProcessing: boolean;
  /** Latest error message, or null */
  error: string | null;
  /** Clear the error */
  clearError: () => void;
  /** Start recording */
  startRecording: () => Promise<void>;
  /** Stop recording */
  stopRecording: () => void;
  /** Toggle recording on/off */
  toggleRecording: () => void;
}

export function useSpeechToText({
  enabled,
  model,
  sectionId,
  onTranscription,
}: UseSpeechToTextOptions): UseSpeechToTextReturn {
  const [isRecording, setIsRecording] = useState(false);
  const [isProcessing, setIsProcessing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const mediaRecorderRef = useRef<MediaRecorder | null>(null);
  const audioStreamRef = useRef<MediaStream | null>(null);
  const audioChunksRef = useRef<Blob[]>([]);
  const recordingTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Stable ref for the callback so MediaRecorder.onstop always has latest
  const onTranscriptionRef = useRef(onTranscription);
  useEffect(() => { onTranscriptionRef.current = onTranscription; }, [onTranscription]);

  const isAvailable =
    enabled &&
    !!model &&
    typeof navigator !== 'undefined' &&
    !!navigator.mediaDevices &&
    typeof navigator.mediaDevices.getUserMedia === 'function';

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (audioStreamRef.current) {
        audioStreamRef.current.getTracks().forEach(t => t.stop());
      }
      if (recordingTimeoutRef.current) {
        clearTimeout(recordingTimeoutRef.current);
      }
    };
  }, []);

  /**
   * Send audio blob to server and call onTranscription with result.
   */
  const processAudioBlob = useCallback(async (audioBlob: Blob) => {
    if (audioBlob.size === 0) {
      setError('No audio recorded');
      return;
    }

    setIsProcessing(true);
    setError(null);

    try {
      const formData = new FormData();
      formData.append('audio', audioBlob, 'recording.webm');
      formData.append('action', 'speech_transcribe');
      formData.append('section_id', (sectionId ?? 0).toString());

      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData,
      });

      const result = await response.json();

      if (result.success && result.text) {
        const text = result.text.trim();
        if (text) {
          onTranscriptionRef.current(text);
        }
      } else if (result.success && !result.text) {
        setError('No speech detected. Please try again.');
      } else {
        setError(result.error || 'Speech transcription failed');
      }
    } catch (err: unknown) {
      console.error('Speech processing error:', err);
      const msg = err instanceof Error ? err.message : 'Unknown error';
      setError('Speech processing failed: ' + msg);
    } finally {
      setIsProcessing(false);
    }
  }, [sectionId]);

  /**
   * Stop recording and process audio.
   */
  const stopRecording = useCallback(() => {
    if (!mediaRecorderRef.current) return;

    if (recordingTimeoutRef.current) {
      clearTimeout(recordingTimeoutRef.current);
      recordingTimeoutRef.current = null;
    }

    if (mediaRecorderRef.current.state === 'recording') {
      mediaRecorderRef.current.stop();
    }

    if (audioStreamRef.current) {
      audioStreamRef.current.getTracks().forEach(t => t.stop());
      audioStreamRef.current = null;
    }

    setIsRecording(false);
  }, []);

  /**
   * Request microphone and start recording.
   */
  const startRecording = useCallback(async () => {
    if (!isAvailable || isRecording) return;

    setError(null);

    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, sampleRate: 16000 },
      });

      audioStreamRef.current = stream;
      audioChunksRef.current = [];

      const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
        ? 'audio/webm;codecs=opus'
        : MediaRecorder.isTypeSupported('audio/webm')
          ? 'audio/webm'
          : 'audio/mp4';

      const recorder = new MediaRecorder(stream, {
        mimeType,
        audioBitsPerSecond: 16000,
      });
      mediaRecorderRef.current = recorder;

      recorder.ondataavailable = (e) => {
        if (e.data.size > 0) audioChunksRef.current.push(e.data);
      };

      recorder.onstop = async () => {
        if (audioChunksRef.current.length > 0) {
          const blob = new Blob(audioChunksRef.current, { type: mimeType });
          await processAudioBlob(blob);
        }
        audioChunksRef.current = [];
      };

      recorder.start();
      setIsRecording(true);

      // Auto-stop after max duration
      recordingTimeoutRef.current = setTimeout(() => {
        if (mediaRecorderRef.current?.state === 'recording') {
          stopRecording();
        }
      }, MAX_RECORDING_DURATION_MS);
    } catch (err: unknown) {
      console.error('Failed to start recording:', err);
      const msg = err instanceof Error ? err.message : 'Unknown error';

      if (msg.includes('Permission denied') || msg.includes('NotAllowedError')) {
        setError('Microphone access denied. Please allow microphone access in your browser settings.');
      } else {
        setError('Failed to start recording: ' + msg);
      }
    }
  }, [isAvailable, isRecording, processAudioBlob, stopRecording]);

  /**
   * Toggle recording on/off.
   */
  const toggleRecording = useCallback(() => {
    if (isRecording) {
      stopRecording();
    } else {
      startRecording();
    }
  }, [isRecording, startRecording, stopRecording]);

  const clearError = useCallback(() => setError(null), []);

  return {
    isAvailable,
    isRecording,
    isProcessing,
    error,
    clearError,
    startRecording,
    stopRecording,
    toggleRecording,
  };
}
