/**
 * useModalDismiss Hook
 * ====================
 * 
 * Shared hook for modal dismiss behavior:
 * - ESC key to close
 * - Click outside (on backdrop) to close
 * - Body overflow lock while open
 * 
 * Extracted from ContextPopup and PayloadPopup which had identical logic.
 * 
 * @module hooks/useModalDismiss
 */

import { useEffect, useRef } from 'react';

/**
 * Hook to handle modal dismiss via ESC key and click-outside
 * 
 * @param show - Whether the modal is currently shown
 * @param onHide - Callback to hide the modal
 * @returns Ref to attach to the backdrop element
 */
export function useModalDismiss(show: boolean, onHide: () => void) {
  const backdropRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!show) return;

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onHide();
      }
    };

    const handleClickOutside = (event: MouseEvent) => {
      if (backdropRef.current && event.target === backdropRef.current) {
        onHide();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    document.addEventListener('mousedown', handleClickOutside);
    document.body.style.overflow = 'hidden';

    return () => {
      document.removeEventListener('keydown', handleKeyDown);
      document.removeEventListener('mousedown', handleClickOutside);
      document.body.style.overflow = '';
    };
  }, [show, onHide]);

  return backdropRef;
}
