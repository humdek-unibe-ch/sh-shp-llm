/**
 * Prompts hub filter bar: the search box, the filters and "Show orphaned".
 *
 * The filters are searchable dropdowns (react-select, the compact style of the LLM admin
 * console): type to narrow a long list of pages, styles or plugins. They offer the values of the
 * current list (`facets`). A value that is chosen but no longer offered (for example after the
 * search text changed) stays in its dropdown, so the bar never shows a filter as "all" that is
 * still applied.
 *
 * @module components/promptsHub/PromptsFilters
 */
import React from 'react';
import Select from 'react-select';
import { Button, Card, Form, InputGroup } from 'react-bootstrap';
import { compactSelectStyles } from '../admin/selectStyles';
import type { PromptFacets, PromptFilters, PromptSourceType } from './promptsHubTypes';
import {
  MAX_QUERY_LENGTH,
  MIN_QUERY_LENGTH,
  SOURCE_LABELS,
  hasActiveFilters,
  isSourceType,
  languageLabel,
} from './promptsHubUtils';

interface PromptsFiltersProps {
  /** What is in the search box (the filter follows after a short pause) */
  searchText: string;
  onSearchText: (text: string) => void;
  filters: PromptFilters;
  facets: PromptFacets;
  onChange: (change: Partial<PromptFilters>) => void;
  onClear: () => void;
}

interface Option {
  value: string;
  label: string;
}

/** "All ..." first, then the offered options, plus the chosen value when it is not among them. */
function optionsOf(allLabel: string, options: Option[], current: string, currentLabel: string): Option[] {
  const offered = current !== '' && !options.some((option) => option.value === current)
    ? [{ value: current, label: currentLabel }, ...options]
    : options;
  return [{ value: '', label: allLabel }, ...offered];
}

/** One searchable filter dropdown with its label. */
const FilterSelect: React.FC<{
  id: string;
  label: string;
  icon: string;
  value: string;
  options: Option[];
  onChange: (value: string) => void;
}> = ({ id, label, icon, value, options, onChange }) => (
  <div className="llm-prompts-filter-col">
    <Form.Label htmlFor={id} className="small text-muted mb-1">
      <i className={`fas ${icon} mr-1`} aria-hidden="true"></i>
      {label}
    </Form.Label>
    <Select<Option, false>
      inputId={id}
      value={options.find((option) => option.value === value) ?? options[0]}
      onChange={(selected) => onChange(selected?.value ?? '')}
      options={options}
      isSearchable
      isClearable={false}
      noOptionsMessage={() => 'Nothing matches'}
      className="react-select-container filter-select"
      classNamePrefix="react-select"
      menuPortalTarget={document.body}
      styles={compactSelectStyles}
    />
  </div>
);

/** Search box and filters of the prompts hub. */
export const PromptsFilters: React.FC<PromptsFiltersProps> = ({
  searchText,
  onSearchText,
  filters,
  facets,
  onChange,
  onClear,
}) => {
  const sourceOptions = optionsOf(
    'All sources',
    facets.sourceTypes.map((source) => ({ value: source, label: SOURCE_LABELS[source] })),
    filters.sourceType,
    filters.sourceType !== '' ? SOURCE_LABELS[filters.sourceType] : ''
  );
  const pluginOptions = optionsOf('All plugins', facets.plugins.map((plugin) => ({ value: plugin, label: plugin })), filters.plugin, filters.plugin);
  const styleOptions = optionsOf('All styles', facets.styles.map((style) => ({ value: style, label: style })), filters.style, filters.style);
  const pageOptions = optionsOf(
    'All pages',
    facets.pages.map((page) => ({ value: String(page.id), label: page.label })),
    filters.pageId,
    'Page #' + filters.pageId
  );
  const languageOptions = optionsOf(
    'All languages',
    facets.languages.map((language) => ({
      value: String(language.id),
      label: languageLabel({ languageId: language.id, languageCode: language.code }),
    })),
    filters.languageId,
    'Language #' + filters.languageId
  );

  const typed = searchText.trim();
  const tooShort = typed.length > 0 && typed.length < MIN_QUERY_LENGTH;

  return (
    <Card className="border llm-prompts-filters mb-3">
      <Card.Body className="py-3">
        <Form onSubmit={(event: React.FormEvent) => event.preventDefault()}>
          <Form.Label htmlFor="llm-prompts-search" className="small text-muted mb-1">
            <i className="fas fa-search mr-1" aria-hidden="true"></i>
            Search
          </Form.Label>
          <InputGroup className="mb-1">
            <Form.Control
              type="text"
              id="llm-prompts-search"
              autoComplete="off"
              maxLength={MAX_QUERY_LENGTH}
              placeholder="Search the text and the names of all prompts"
              value={searchText}
              onChange={(event: React.ChangeEvent<HTMLInputElement>) => onSearchText(event.target.value)}
            />
            {searchText !== '' && (
              <InputGroup.Append>
                <Button variant="outline-secondary" aria-label="Clear the search" onClick={() => onSearchText('')}>
                  <i className="fas fa-times" aria-hidden="true"></i>
                </Button>
              </InputGroup.Append>
            )}
          </InputGroup>
          {tooShort && <Form.Text muted className="mt-0">Type at least {MIN_QUERY_LENGTH} characters to search.</Form.Text>}

          <div className="llm-prompts-filter-grid mt-2">
            <FilterSelect
              id="llm-prompts-source"
              label="Source"
              icon="fa-layer-group"
              value={filters.sourceType}
              options={sourceOptions}
              onChange={(value) => onChange({ sourceType: isSourceType(value) ? (value as PromptSourceType) : '' })}
            />
            <FilterSelect
              id="llm-prompts-plugin"
              label="Plugin"
              icon="fa-puzzle-piece"
              value={filters.plugin}
              options={pluginOptions}
              onChange={(value) => onChange({ plugin: value })}
            />
            <FilterSelect
              id="llm-prompts-style"
              label="Style"
              icon="fa-palette"
              value={filters.style}
              options={styleOptions}
              onChange={(value) => onChange({ style: value })}
            />
            <FilterSelect
              id="llm-prompts-page"
              label="Page"
              icon="fa-file"
              value={filters.pageId}
              options={pageOptions}
              onChange={(value) => onChange({ pageId: value })}
            />
            <FilterSelect
              id="llm-prompts-language"
              label="Language"
              icon="fa-language"
              value={filters.languageId}
              options={languageOptions}
              onChange={(value) => onChange({ languageId: value })}
            />
          </div>

          <div className="d-flex align-items-center flex-wrap mt-2">
            <Form.Check
              custom
              type="checkbox"
              id="llm-prompts-orphaned"
              className="mr-3 small"
              label="Show orphaned"
              title="Prompts that nothing uses any more (read-only)"
              checked={filters.includeOrphaned}
              onChange={(event: React.ChangeEvent<HTMLInputElement>) => onChange({ includeOrphaned: event.target.checked })}
            />
            {(hasActiveFilters(filters) || searchText !== '') && (
              <Button variant="outline-danger" size="sm" className="ml-auto" onClick={onClear}>
                <i className="fas fa-times mr-1" aria-hidden="true"></i>
                Clear search and filters
              </Button>
            )}
          </div>
        </Form>
      </Card.Body>
    </Card>
  );
};
