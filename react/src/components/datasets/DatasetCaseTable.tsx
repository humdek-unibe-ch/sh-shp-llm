import React from 'react';
import { Badge, Button, Table } from 'react-bootstrap';
import type { PromptDatasetCase } from './datasetTypes';

interface DatasetCaseTableProps {
  cases: PromptDatasetCase[];
  loading?: boolean;
  canDelete?: boolean;
  onPreview: (datasetCase: PromptDatasetCase) => void;
  onDelete: (datasetCase: PromptDatasetCase) => void;
}

export const DatasetCaseTable: React.FC<DatasetCaseTableProps> = ({
  cases,
  loading = false,
  canDelete = true,
  onPreview,
  onDelete,
}) => (
  <div className="table-responsive">
    <Table hover size="sm" className="mb-0 prompt-lab-table">
      <thead>
        <tr>
          <th>Case</th>
          <th>Type</th>
          <th>Source</th>
          <th>Tags</th>
          <th style={{ width: 140 }}>Actions</th>
        </tr>
      </thead>
      <tbody>
        {loading ? (
          <tr><td colSpan={5} className="text-muted small">Loading cases...</td></tr>
        ) : cases.length === 0 ? (
          <tr><td colSpan={5} className="text-muted small">No dataset cases yet.</td></tr>
        ) : cases.map((datasetCase) => {
          let tags: string[] = [];
          try {
            tags = JSON.parse(datasetCase.tags_json || '[]') as string[];
          } catch {
            tags = [];
          }
          return (
            <tr key={datasetCase.id}>
              <td className="small">{datasetCase.title || datasetCase.case_key}</td>
              <td className="small">{datasetCase.case_type_code || '-'}</td>
              <td className="small">{datasetCase.source_type_code || '-'}</td>
              <td className="small">
                {tags.length === 0 ? '-' : tags.map((tag) => (
                  <Badge key={`${datasetCase.id}:${tag}`} variant="light" className="mr-1">{tag}</Badge>
                ))}
              </td>
              <td>
                <div className="prompt-row-actions">
                  <Button size="sm" variant="outline-secondary" onClick={() => onPreview(datasetCase)}>Preview</Button>
                  {canDelete && <Button size="sm" variant="outline-danger" onClick={() => onDelete(datasetCase)}>Remove</Button>}
                </div>
              </td>
            </tr>
          );
        })}
      </tbody>
    </Table>
  </div>
);
