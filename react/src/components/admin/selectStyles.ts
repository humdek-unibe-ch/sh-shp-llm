/**
 * Shared react-select Styles
 * ==========================
 * 
 * Compact 38px-height styles used by the admin filter dropdowns.
 * Extracted to eliminate the identical 30-line inline style object
 * that was duplicated for every <Select> in AdminConsole.
 * 
 * @module components/admin/selectStyles
 */

import type { StylesConfig } from 'react-select';

interface SelectOption {
  value: string;
  label: string;
}

/** compactSelectStyles constant or utility. */
export const compactSelectStyles: StylesConfig<SelectOption, false> = {
  control: (provided) => ({
    ...provided,
    minHeight: '38px',
    height: '38px',
    fontSize: '0.875rem',
  }),
  valueContainer: (provided) => ({
    ...provided,
    height: '38px',
    padding: '0 8px',
  }),
  input: (provided) => ({
    ...provided,
    margin: '0',
    padding: '0',
  }),
  indicatorsContainer: (provided) => ({
    ...provided,
    height: '38px',
  }),
  option: (provided) => ({
    ...provided,
    fontSize: '0.875rem',
  }),
  singleValue: (provided) => ({
    ...provided,
    fontSize: '0.875rem',
  }),
  menuPortal: (base) => ({
    ...base,
    zIndex: 9999,
  }),
};
