/**
 * Memory Key Editor — label, description and sections of one memory key (Keys tab).
 *
 * Sections are optional: a key without sections stores its memory as one flat list of
 * items. With sections, every item belongs to one of them; `code` is stored with every
 * item (it cannot be renamed once items use it, so the field is locked for saved
 * sections), `label` is the default heading (participants see it translated through the
 * editor's `memory_labels` field) and `hint` tells the memory model what belongs there.
 * The server validates the sections again when they are saved.
 *
 * @module components/memory/MemoryKeyEditor
 */
import React, { useState } from 'react';
import { Button, Form } from 'react-bootstrap';
import type { MemoryKeySection } from '../../utils/api';

interface Props {
  code: string;
  label: string;
  description: string;
  sections: MemoryKeySection[];
  saving: boolean;
  onSave: (label: string, description: string, sections: MemoryKeySection[]) => void;
  onCancel: () => void;
}

const CODE_PATTERN = /^[A-Za-z0-9_-]{1,40}$/;

/** A code from a label: lower case, letters/digits/underscore. */
function codeFromLabel(label: string): string {
  return label.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 40);
}

/** MemoryKeyEditor component. */
export const MemoryKeyEditor: React.FC<Props> = ({ code, label, description, sections, saving, onSave, onCancel }) => {
  const [draftLabel, setDraftLabel] = useState(label);
  const [draftDescription, setDraftDescription] = useState(description);
  const [draftSections, setDraftSections] = useState<MemoryKeySection[]>(sections.map((section) => ({ ...section })));
  // codes that are saved already: items may use them, so they are not editable
  const savedCodes = new Set(sections.map((section) => section.code));

  const update = (index: number, changes: Partial<MemoryKeySection>) => {
    setDraftSections((current) => current.map((section, i) => (i === index ? { ...section, ...changes } : section)));
  };
  const move = (index: number, offset: number) => {
    setDraftSections((current) => {
      const next = [...current];
      const [section] = next.splice(index, 1);
      next.splice(index + offset, 0, section);
      return next;
    });
  };

  const codes = draftSections.map((section) => section.code.trim());
  const invalid = draftSections.some((section, index) =>
    !CODE_PATTERN.test(section.code.trim()) || codes.indexOf(section.code.trim()) !== index);

  return (
    <div className="border rounded p-3 mb-2 bg-light">
      <div className="small text-muted mb-2">Key <code>{code}</code> (the code cannot be changed)</div>
      <Form.Group className="mb-2">
        <Form.Label className="small mb-1">Label</Form.Label>
        <Form.Control size="sm" value={draftLabel} onChange={(e) => setDraftLabel(e.target.value)} />
      </Form.Group>
      <Form.Group className="mb-3">
        <Form.Label className="small mb-1">Description</Form.Label>
        <Form.Control size="sm" as="textarea" rows={2} value={draftDescription} onChange={(e) => setDraftDescription(e.target.value)} />
      </Form.Group>

      <div className="font-weight-bold small mb-1">Sections</div>
      <div className="small text-muted mb-2">
        Optional. Without sections the memory is one list of facts. With sections every fact belongs to one of them.
        Label: the heading (translate it for participants with the <code>memory_labels</code> field of the llmMemoryEditor style).
        Hint: tells the memory model what belongs in the section. Removing a section keeps its facts: they are shown after the other sections.
      </div>
      {draftSections.length === 0 ? (
        <div className="small text-muted mb-2">No sections: one flat list.</div>
      ) : draftSections.map((section, index) => {
        const trimmed = section.code.trim();
        const codeError = !CODE_PATTERN.test(trimmed)
          ? '1 to 40 letters, digits, _ or -'
          : codes.indexOf(trimmed) !== index ? 'Used twice' : '';
        return (
          <div key={index} className="d-flex flex-wrap align-items-start mb-2" style={{ gap: '.5rem' }}>
            <Form.Control
              size="sm" style={{ maxWidth: '10rem' }} placeholder="code"
              value={section.code}
              disabled={savedCodes.has(section.code)}
              isInvalid={codeError !== ''}
              title={codeError || 'Stored with every fact of this section'}
              onChange={(e) => update(index, { code: e.target.value })}
            />
            <Form.Control
              size="sm" style={{ maxWidth: '12rem' }} placeholder="Label"
              value={section.label}
              onChange={(e) => {
                const value = e.target.value;
                // a new section gets its code from the label until the code is typed itself
                const auto = !savedCodes.has(section.code) && (section.code === '' || section.code === codeFromLabel(section.label));
                update(index, auto ? { label: value, code: codeFromLabel(value) } : { label: value });
              }}
            />
            <Form.Control
              size="sm" style={{ flex: '1 1 14rem' }} placeholder="Hint for the memory model"
              value={section.hint}
              onChange={(e) => update(index, { hint: e.target.value })}
            />
            <div className="btn-group btn-group-sm">
              <Button variant="outline-secondary" disabled={index === 0} onClick={() => move(index, -1)} title="Move up">↑</Button>
              <Button variant="outline-secondary" disabled={index === draftSections.length - 1} onClick={() => move(index, 1)} title="Move down">↓</Button>
              <Button variant="outline-danger" onClick={() => setDraftSections((current) => current.filter((_, i) => i !== index))} title="Remove section">✕</Button>
            </div>
          </div>
        );
      })}
      <Button size="sm" variant="outline-primary" className="mb-3"
        onClick={() => setDraftSections((current) => [...current, { code: '', label: '', hint: '' }])}>
        + Add section
      </Button>

      <div>
        <Button size="sm" variant="primary" className="mr-2" disabled={saving || invalid}
          onClick={() => onSave(draftLabel, draftDescription, draftSections.map((s) => ({ code: s.code.trim(), label: s.label.trim(), hint: s.hint.trim() })))}>
          {saving ? 'Saving...' : 'Save'}
        </Button>
        <Button size="sm" variant="outline-secondary" disabled={saving} onClick={onCancel}>Cancel</Button>
      </div>
    </div>
  );
};
