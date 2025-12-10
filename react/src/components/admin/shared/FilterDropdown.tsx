import React, { useMemo, useState } from 'react';

export type DropdownOption = {
  value: string;
  label: string;
  subtitle?: string;
};

type FilterDropdownProps = {
  label: string;
  options: DropdownOption[];
  value: string;
  placeholder?: string;
  onChange: (value: string) => void;
};

export const FilterDropdown: React.FC<FilterDropdownProps> = ({
  label,
  options,
  value,
  placeholder = 'All',
  onChange
}) => {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');

  const filtered = useMemo(() => {
    if (!search) return options;
    return options.filter(
      (opt) =>
        opt.label.toLowerCase().includes(search.toLowerCase()) ||
        (opt.subtitle && opt.subtitle.toLowerCase().includes(search.toLowerCase()))
    );
  }, [options, search]);

  const selected = options.find((opt) => opt.value === value);

  return (
    <div className="form-group position-relative">
      <label className="font-weight-bold small text-muted mb-1">{label}</label>
      <button
        type="button"
        className="btn btn-outline-secondary btn-block text-left d-flex justify-content-between align-items-center"
        onClick={() => setOpen(!open)}
      >
        <span className="text-truncate">{selected ? selected.label : placeholder}</span>
        <span className="text-muted small ml-2">&#9662;</span>
      </button>
      {open && (
        <div className="card shadow-sm mt-1 position-absolute w-100 llm-admin-dropdown">
          <div className="px-3 pt-3">
            <input
              type="text"
              className="form-control form-control-sm"
              placeholder="Search..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              autoFocus
            />
          </div>
          <div className="list-group list-group-flush llm-admin-dropdown-list">
            {filtered.map((opt) => (
              <button
                key={opt.value}
                type="button"
                className={`list-group-item list-group-item-action d-flex justify-content-between align-items-center ${
                  opt.value === value ? 'active' : ''
                }`}
                onClick={() => {
                  onChange(opt.value);
                  setOpen(false);
                }}
              >
                <div className="text-left">
                  <div className="font-weight-bold text-truncate">{opt.label}</div>
                  {opt.subtitle && <div className="small text-muted text-truncate">{opt.subtitle}</div>}
                </div>
                {opt.value === value && <span className="text-success ml-2">&#10003;</span>}
              </button>
            ))}
            {filtered.length === 0 && <div className="list-group-item text-muted small">No results</div>}
          </div>
        </div>
      )}
    </div>
  );
};


