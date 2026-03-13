import type { createPromptLabApi } from '../prompts/promptApi';
import type { PromptDescriptor, PromptExecutionProfile } from '../prompts/promptTypes';
import type {
  PromptDataset,
  PromptDatasetCase,
  PromptImportCandidate,
  PromptImportSourceType,
} from './datasetTypes';

type PromptLabApi = ReturnType<typeof createPromptLabApi>;

export function createDatasetApi(api: PromptLabApi) {
  return {
    listDatasets(descriptor: PromptDescriptor, executionProfile: PromptExecutionProfile, search = ''): Promise<PromptDataset[]> {
      return api.listDatasets(descriptor, {
        search,
        ownerTypeScope: descriptor.ownerType,
        ownerIdScope: descriptor.ownerId,
        executionProfile,
      });
    },
    createDataset(
      descriptor: PromptDescriptor,
      name: string,
      executionProfile: PromptExecutionProfile,
      description = '',
      datasetType = 'golden_manual',
    ): Promise<PromptDataset> {
      return api.createDataset(descriptor, name, executionProfile, description, datasetType);
    },
    getDataset(descriptor: PromptDescriptor, datasetId: number): Promise<{ dataset: PromptDataset; cases: PromptDatasetCase[] }> {
      return api.getDataset(descriptor, datasetId);
    },
    updateDataset(
      descriptor: PromptDescriptor,
      datasetId: number,
      payload: {
        name?: string;
        description?: string;
        datasetType?: string;
        executionProfile?: string;
        isLocked?: boolean;
      },
    ): Promise<PromptDataset> {
      return api.updateDataset(descriptor, datasetId, payload);
    },
    deleteDataset(descriptor: PromptDescriptor, datasetId: number): Promise<{ deleted: boolean }> {
      return api.deleteDataset(descriptor, datasetId);
    },
    addCaseFromPlaygroundRun: api.addCaseFromPlaygroundRun,
    getImportCandidates(descriptor: PromptDescriptor, sourceType: PromptImportSourceType, limit = 50): Promise<PromptImportCandidate[]> {
      return api.getImportCandidates(descriptor, sourceType, limit);
    },
    addCasesFromSource: api.addCasesFromSource,
    deleteDatasetCase(descriptor: PromptDescriptor, datasetCaseId: number): Promise<{ deleted: boolean }> {
      return api.deleteDatasetCase(descriptor, datasetCaseId);
    },
  };
}
