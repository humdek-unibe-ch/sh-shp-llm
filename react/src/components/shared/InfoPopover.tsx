import React, { useEffect, useRef, useState } from 'react';
import { Overlay, Popover } from 'react-bootstrap';
import './InfoPopover.css';

interface InfoPopoverProps {
  title: React.ReactNode;
  children: React.ReactNode;
  placement?: 'top' | 'right' | 'bottom' | 'left';
  buttonClassName?: string;
  iconClassName?: string;
  ariaLabel?: string;
  popoverClassName?: string;
}

/** Shared info popover with hover preview and click-to-pin behavior. */
export const InfoPopover: React.FC<InfoPopoverProps> = ({
  title,
  children,
  placement = 'top',
  buttonClassName = '',
  iconClassName = '',
  ariaLabel = 'More information',
  popoverClassName = '',
}) => {
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  const overlayRef = useRef<HTMLDivElement | null>(null);
  const [show, setShow] = useState(false);
  const [pinned, setPinned] = useState(false);
  const pinnedRef = useRef(false);
  const popoverIdRef = useRef(`info-popover-${Math.random().toString(36).slice(2, 11)}`);

  useEffect(() => {
    pinnedRef.current = pinned;
  }, [pinned]);

  useEffect(() => {
    if (!show) {
      return undefined;
    }

    const handlePointerDown = (event: MouseEvent | TouchEvent) => {
      const target = event.target as Node | null;
      if (!target) {
        return;
      }
      if (triggerRef.current?.contains(target) || overlayRef.current?.contains(target)) {
        return;
      }
      setShow(false);
      setPinned(false);
    };

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setShow(false);
        setPinned(false);
      }
    };

    document.addEventListener('mousedown', handlePointerDown);
    document.addEventListener('touchstart', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('mousedown', handlePointerDown);
      document.removeEventListener('touchstart', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [show]);

  const openPreview = () => {
    if (!pinnedRef.current) {
      setShow(true);
    }
  };

  const closePreview = () => {
    if (!pinnedRef.current) {
      setShow(false);
    }
  };

  const togglePinned = () => {
    if (pinnedRef.current) {
      pinnedRef.current = false;
      setPinned(false);
      setShow(false);
      return;
    }
    pinnedRef.current = true;
    setPinned(true);
    setShow(true);
  };

  const setOverlayNode = (node: HTMLDivElement | null) => {
    overlayRef.current = node;
    const overlayRefProp = (overlayPropsRef.current as any);
    if (!overlayRefProp) {
      return;
    }
    if (typeof overlayRefProp === 'function') {
      overlayRefProp(node);
      return;
    }
    overlayRefProp.current = node;
  };

  const overlayPropsRef = useRef<unknown>(null);

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        className={`btn btn-link btn-sm text-secondary p-0 info-popover-trigger ${show ? 'is-active' : ''} ${buttonClassName}`.trim()}
        aria-label={ariaLabel}
        aria-expanded={show}
        onMouseEnter={openPreview}
        onMouseLeave={closePreview}
        onFocus={openPreview}
        onBlur={closePreview}
        onClick={togglePinned}
      >
        <i className={`fas fa-info-circle ${iconClassName}`.trim()}></i>
      </button>
      <Overlay target={triggerRef.current} show={show} placement={placement}>
        {(overlayProps) => {
          overlayPropsRef.current = overlayProps.ref;
          return (
          <Popover
            {...overlayProps}
            id={popoverIdRef.current}
            ref={setOverlayNode as any}
            className={`info-popover-overlay ${popoverClassName}`.trim()}
          >
            <Popover.Title as="h3">{title}</Popover.Title>
            <Popover.Content>{children}</Popover.Content>
          </Popover>
          );
        }}
      </Overlay>
    </>
  );
};
