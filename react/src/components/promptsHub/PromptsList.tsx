/**
 * Prompts hub list: one row per prompt, one page of the list at a time.
 *
 * Search hits are shown as the snippets the server found, with the match highlighted. The
 * highlight is built from React text and `<mark>` elements (splitHighlights), never from HTML:
 * prompt text is data and is never interpreted.
 *
 * @module components/promptsHub/PromptsList
 */
import React from 'react';
import { Badge, Button, Card, Form, Pagination, Spinner, Table } from 'react-bootstrap';
import { InfoPopover } from '../shared/InfoPopover';
import type { PromptRow } from './promptsHubTypes';
import {
  PAGE_SIZES,
  SOURCE_BADGE_VARIANT,
  SOURCE_LABELS,
  formatWhen,
  languageLabel,
  pageCount,
  pageWindow,
  readOnlyText,
  splitHighlights,
  versionLabel,
  whereParts,
} from './promptsHubUtils';

interface PromptsListProps {
  rows: PromptRow[];
  /** Rows matching the filters, on all pages */
  total: number;
  /** The search text the rows were found with (what to highlight) */
  query: string;
  selectedKey: string | null;
  loading: boolean;
  hasFilters: boolean;
  /** The page shown (1-based) */
  listPage: number;
  pageSize: number;
  onSelect: (key: string) => void;
  onPage: (page: number) => void;
  onPageSize: (size: number) => void;
  onClear: () => void;
}

/** Where the date and the author of the "Updated" column come from. */
export const UpdatedInfo: React.FC<{ placement?: 'top' | 'bottom' | 'left' | 'right' }> = ({ placement = 'bottom' }) => (
  <InfoPopover title="Where &ldquo;Updated&rdquo; comes from" placement={placement} buttonClassName="ml-1" ariaLabel="Where the update date comes from">
    <p className="small mb-2">
      The CMS keeps no date for a field text. The date and the author are those of the
      prompt&rsquo;s active <strong>version</strong> (the prompt version history, <code>llm_prompt_versions</code>).
    </p>
    <p className="small mb-2">
      A version is written by every save of the prompt: a prompt field saved in the CMS, the Prompt Lab,
      the Scripts and Memory pages, and this page. A script or memory rule without a version shows the
      date of its own last change.
    </p>
    <p className="small mb-0">
      &ldquo;&mdash;&rdquo; means the text has no version yet: it was never saved since the version
      history exists (for example it was imported or written directly into the database).
    </p>
  </InfoPopover>
);

/** A text with the parts that match the search in `<mark>`. */
export const Highlighted: React.FC<{ text: string; query: string }> = ({ text, query }) => (
  <>
    {splitHighlights(text, query).map((part, index) =>
      part.match ? <mark key={index}>{part.text}</mark> : <React.Fragment key={index}>{part.text}</React.Fragment>
    )}
  </>
);

/** Table of prompts. */
export const PromptsList: React.FC<PromptsListProps> = ({
  rows,
  total,
  query,
  selectedKey,
  loading,
  hasFilters,
  listPage,
  pageSize,
  onSelect,
  onPage,
  onPageSize,
  onClear,
}) => {
  const pages = pageCount(total, pageSize);

  if (loading && rows.length === 0) {
    return (
      <Card className="border">
        <Card.Body className="text-center text-muted py-5">
          <Spinner animation="border" size="sm" className="mr-2" />
          Loading prompts...
        </Card.Body>
      </Card>
    );
  }

  if (rows.length === 0) {
    return (
      <Card className="border">
        <Card.Body className="text-center text-muted py-5">
          {hasFilters ? (
            <>
              <p className="mb-2">No prompt matches the search and the filters.</p>
              <Button variant="outline-secondary" size="sm" onClick={onClear}>Clear search and filters</Button>
            </>
          ) : (
            <p className="mb-0">There are no prompts yet.</p>
          )}
        </Card.Body>
      </Card>
    );
  }

  const first = (listPage - 1) * pageSize + 1;
  const last = Math.min(total, first + rows.length - 1);

  return (
    <Card className={loading ? 'border llm-prompts-list llm-prompts-list-busy' : 'border llm-prompts-list'}>
      <Table hover responsive size="sm" className="llm-prompts-table mb-0">
        <thead>
          <tr>
            <th scope="col">Source</th>
            <th scope="col">Prompt</th>
            <th scope="col">Where</th>
            <th scope="col">Language</th>
            <th scope="col">Version</th>
            <th scope="col">
              Updated
              <UpdatedInfo />
            </th>
            <th scope="col"><span className="sr-only">Read-only</span></th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const selected = row.key === selectedKey;
            const where = whereParts(row);
            const lockText = readOnlyText(row.readOnlyReason) || 'Read-only';
            return (
              <tr
                key={row.key}
                className={selected ? 'llm-prompts-row-selected' : undefined}
                aria-current={selected ? 'true' : undefined}
                onClick={() => onSelect(row.key)}
              >
                <td className="text-nowrap">
                  <Badge variant={SOURCE_BADGE_VARIANT[row.sourceType]}>{SOURCE_LABELS[row.sourceType]}</Badge>
                  <div className="small text-muted">{row.plugin}</div>
                </td>
                <td>
                  <button
                    type="button"
                    className="btn btn-link p-0 text-left font-weight-bold llm-prompts-title"
                    onClick={(event: React.MouseEvent) => {
                      event.stopPropagation();
                      onSelect(row.key);
                    }}
                  >
                    {row.title}
                  </button>
                  {row.orphaned && <Badge variant="light" className="ml-2 border">orphaned</Badge>}
                  {row.matches.length > 0 ? (
                    <ul className="list-unstyled mb-0 llm-prompts-snippets">
                      {row.matches.map((match, index) => (
                        <li key={index} className="small">
                          <span className="text-muted mr-1">L{match.line}</span>
                          <Highlighted text={match.text} query={query} />
                        </li>
                      ))}
                    </ul>
                  ) : row.preview !== '' ? (
                    <div className="small text-muted llm-prompts-preview">{row.preview}</div>
                  ) : (
                    <div className="small text-muted font-italic">empty</div>
                  )}
                </td>
                <td className="small">
                  {where.owner}
                  {where.page !== null && (
                    <div className="text-muted" title={where.pageTitle ?? undefined}>{where.page}</div>
                  )}
                </td>
                <td className="small text-nowrap">{languageLabel(row)}</td>
                <td className="small text-nowrap">{versionLabel(row)}</td>
                <td className="small text-nowrap">
                  {row.updatedAt ? formatWhen(row.updatedAt) : <span className="text-muted">&mdash;</span>}
                  {row.updatedBy && <div className="text-muted">{row.updatedBy}</div>}
                </td>
                <td className="text-center">
                  {!row.editable && (
                    <span title={lockText} role="img" aria-label={lockText}>
                      <i className="fas fa-lock text-muted" aria-hidden="true"></i>
                    </span>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </Table>
      <Card.Footer className="bg-light py-2">
        <div className="d-flex justify-content-between align-items-center flex-wrap">
          <div className="d-flex align-items-center small text-muted mr-3 my-1">
            <span className="mr-3">
              {first}&ndash;{last} of {total.toLocaleString()}
            </span>
            <Form.Label htmlFor="llm-prompts-page-size" className="mb-0 mr-2">Per page</Form.Label>
            <Form.Control
              as="select"
              custom
              size="sm"
              id="llm-prompts-page-size"
              className="llm-prompts-page-size"
              value={pageSize}
              onChange={(event: React.ChangeEvent<HTMLSelectElement>) => onPageSize(Number(event.target.value))}
            >
              {PAGE_SIZES.map((size) => (
                <option key={size} value={size}>{size}</option>
              ))}
            </Form.Control>
          </div>
          {pages > 1 && (
            <Pagination size="sm" className="mb-0 my-1" aria-label="Pages of the prompt list">
              <Pagination.First disabled={listPage <= 1 || loading} onClick={() => onPage(1)} aria-label="First page" />
              <Pagination.Prev disabled={listPage <= 1 || loading} onClick={() => onPage(listPage - 1)} aria-label="Previous page" />
              {pageWindow(listPage, pages).map((page, index) =>
                page === null ? (
                  <Pagination.Ellipsis key={'gap-' + index} disabled />
                ) : (
                  <Pagination.Item key={page} active={page === listPage} disabled={loading} onClick={() => onPage(page)}>
                    {page}
                  </Pagination.Item>
                )
              )}
              <Pagination.Next disabled={listPage >= pages || loading} onClick={() => onPage(listPage + 1)} aria-label="Next page" />
              <Pagination.Last disabled={listPage >= pages || loading} onClick={() => onPage(pages)} aria-label="Last page" />
            </Pagination>
          )}
        </div>
      </Card.Footer>
    </Card>
  );
};
