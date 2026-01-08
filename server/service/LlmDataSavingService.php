<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

require_once __DIR__ . '/base/BaseLlmService.php';

/**
 * LLM Data Saving Service
 * =======================
 *
 * Service class for saving LLM form data to SelfHelp's UserInput system.
 * Uses the standard SelfHelp dataTables/dataRows/dataCells architecture
 * for consistent data storage and retrieval.
 *
 * ## Data Architecture
 *
 * This service integrates with SelfHelp's unified data storage system:
 * - **dataTables**: Defines the table structure (one per llmChat section)
 * - **dataCols**: Column definitions (created dynamically from form fields)
 * - **dataRows**: Individual records with user associations
 * - **dataCells**: Individual cell values
 *
 * ## Save Modes
 *
 * - **log**: Each form submission creates a new row (tracking over time)
 * - **record**: Updates user's existing record or creates new (profiles/preferences)
 *
 * ## Variable Naming
 *
 * The LLM is instructed to use proper variable names (snake_case) based on
 * what data is being collected. The field IDs from the form definition
 * become column names in the dataTable.
 *
 * @package LLM Plugin
 * @see UserInput::save_data() for the underlying save mechanism
 */
class LlmDataSavingService extends BaseLlmService
{
    /** @var object UserInput service for data operations */
    private $user_input;

    /** @var string Table name prefix for LLM chat data tables */
    const TABLE_PREFIX = 'llmChat_';

    /**
     * Constructor
     *
     * @param object $services Services container
     */
    public function __construct($services)
    {
        parent::__construct($services);
        $this->user_input = $services->get_user_input();
    }

    /**
     * Get the dataTable name for a section
     * 
     * Uses the section ID to create a unique, consistent table name.
     * Format: llmChat_{section_id}
     * 
     * @param int $section_id The llmChat section ID
     * @return string The table name
     */
    public function getTableName($section_id)
    {
        return self::TABLE_PREFIX . $section_id;
    }

    /**
     * Save form data to the section's data table using SelfHelp's UserInput system
     * 
     * This method uses the standard SelfHelp save_data() function which:
     * - Creates the dataTable if it doesn't exist
     * - Creates columns dynamically as needed
     * - Handles both insert (log mode) and update (record mode) operations
     * - Triggers any configured actions/jobs
     * - Logs transactions for audit trail
     * 
     * @param int $section_id The llmChat section ID
     * @param int $user_id The user who submitted the form
     * @param array $form_values The form field values (field_id => value)
     * @param array $form_definition Optional form definition for metadata
     * @param int|null $message_id Optional message ID to link
     * @param int|null $conversation_id Optional conversation ID
     * @param string $mode 'log' or 'record'
     * @return int|false The inserted/updated record ID, or false on failure
     */
    public function saveFormData(
        $section_id,
        $user_id,
        $form_values,
        $form_definition = [],
        $message_id = null,
        $conversation_id = null,
        $mode = 'log'
    ) {
        if (empty($form_values) || !is_array($form_values)) {
            return false;
        }

        $table_name = $this->getTableName($section_id);

        // Prepare data for saving
        $data = $this->prepareDataForStorage($form_values, $user_id, $message_id, $conversation_id);

        if (empty($data) || count($data) <= 1) {
            // Only id_users was added, no actual form data
            return false;
        }

        try {
            // For record mode, check if user already has a record
            // If no record exists, use log mode (insert) instead of update
            $updateBasedOn = null;
            if ($mode === 'record') {
                // Check if user already has a record in this table
                $existingRecord = $this->getUserRecord($section_id, $user_id);
                
                if ($existingRecord) {
                    // User has existing record - use update mode
                    $updateBasedOn = ['id_users' => $user_id];
                }
                // If no existing record, updateBasedOn stays null = insert new record
            }

            // Use SelfHelp's standard save_data function
            $result = $this->user_input->save_data(
                TRANSACTION_BY_LLM_PLUGIN,
                $table_name,
                $data,
                $updateBasedOn,
                true // own_entries_only - only update user's own data
            );

            return $result;

        } catch (Exception $e) {
            error_log("LlmDataSavingService: Error saving data: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if user has an existing record in the section's data table
     * 
     * @param int $section_id The section ID
     * @param int $user_id The user ID
     * @return array|null The existing record or null if not found
     */
    private function getUserRecord($section_id, $user_id)
    {
        $table_name = $this->getTableName($section_id);
        $table_id = $this->user_input->get_dataTable_id($table_name);

        if (!$table_id) {
            return null;
        }

        // Get user's records from this table
        $records = $this->user_input->get_data_for_user($table_id, $user_id, '', false);
        
        if (!empty($records)) {
            // Return the first (most recent) record
            return reset($records);
        }

        return null;
    }

    /**
     * Prepare form values for database storage
     * 
     * Converts form values to the format expected by UserInput::save_data():
     * - Sanitizes field names to valid column names
     * - Converts arrays to JSON strings
     * - Adds user_id and metadata columns
     * 
     * @param array $form_values The raw form values
     * @param int $user_id The user ID
     * @param int|null $message_id Optional message ID
     * @param int|null $conversation_id Optional conversation ID
     * @return array Prepared data ready for save_data()
     */
    private function prepareDataForStorage($form_values, $user_id, $message_id = null, $conversation_id = null)
    {
        $data = [];

        // Add user ID (required by save_data)
        $data['id_users'] = $user_id;

        // Add LLM-specific metadata columns
        if ($message_id) {
            $data['llm_message_id'] = $message_id;
        }
        if ($conversation_id) {
            $data['llm_conversation_id'] = $conversation_id;
        }

        // Process form field values
        foreach ($form_values as $field_id => $value) {
            // Skip internal fields that start with underscore
            if (strpos($field_id, '_') === 0) {
                continue;
            }

            // Sanitize field ID to valid column name
            $column_name = $this->sanitizeColumnName($field_id);
            if (!$column_name) {
                continue;
            }

            // Convert arrays to JSON (for checkbox multi-select)
            if (is_array($value)) {
                $data[$column_name] = json_encode($value);
            } else {
                $data[$column_name] = $value;
            }
        }

        return $data;
    }

    /**
     * Sanitize a field ID to a valid column name
     * 
     * Column names must:
     * - Start with a letter
     * - Contain only alphanumeric characters and underscores
     * - Be max 64 characters
     * 
     * @param string $field_id The raw field ID from the form
     * @return string|null Safe column name, or null if invalid
     */
    private function sanitizeColumnName($field_id)
    {
        // Convert to lowercase and replace invalid chars with underscore
        $safe = preg_replace('/[^a-z0-9_]/', '_', strtolower($field_id));

        // Remove leading numbers or underscores
        $safe = preg_replace('/^[0-9_]+/', '', $safe);

        // Remove consecutive underscores
        $safe = preg_replace('/_+/', '_', $safe);

        // Trim underscores from ends
        $safe = trim($safe, '_');

        // Limit length (MySQL column name limit is 64)
        $safe = substr($safe, 0, 64);

        // Must have at least one character
        if (empty($safe)) {
            return null;
        }

        // Avoid reserved column names used by the system
        $reserved = ['id', 'id_users', 'id_dataTables', 'id_dataRows', 'timestamp', 'deleted'];
        if (in_array($safe, $reserved)) {
            $safe = 'field_' . $safe;
        }

        return $safe;
    }

    /**
     * Initialize a dataTable for an llmChat section
     * 
     * Called when a new llmChat section is created or when data saving
     * is enabled. Creates the dataTable entry with proper display name.
     * 
     * @param int $section_id The section ID
     * @param string $display_name Optional display name for the table
     * @return int|false The dataTable ID or false on failure
     */
    public function initializeDataTable($section_id, $display_name = '')
    {
        $table_name = $this->getTableName($section_id);

        // Check if table already exists
        $existing_id = $this->user_input->get_dataTable_id($table_name);
        if ($existing_id) {
            // Table exists, update display name if provided
            if (!empty($display_name)) {
                $this->updateTableDisplayName($section_id, $display_name);
            }
            return $existing_id;
        }

        // Create the dataTable entry
        try {
            $table_id = $this->db->insert("dataTables", [
                "name" => $table_name,
                "displayName" => $display_name ?: "LLM Chat Data ({$section_id})"
            ]);

            if ($table_id) {
                error_log("LlmDataSavingService: Created dataTable {$table_name} with ID {$table_id}");
            }

            return $table_id;
        } catch (Exception $e) {
            error_log("LlmDataSavingService: Error creating dataTable: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Update the display name for a section's data table
     * 
     * @param int $section_id The section ID
     * @param string $display_name The new display name
     * @return bool Success status
     */
    public function updateTableDisplayName($section_id, $display_name)
    {
        $table_name = $this->getTableName($section_id);

        try {
            $table_id = $this->user_input->get_dataTable_id($table_name);
            if (!$table_id) {
                return false;
            }

            $this->db->execute_update_db(
                "UPDATE dataTables SET displayName = :displayName WHERE id = :id",
                [':displayName' => $display_name, ':id' => $table_id]
            );

            return true;
        } catch (Exception $e) {
            error_log("LlmDataSavingService: Error updating display name: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all data for a user from a section's table
     * 
     * @param int $section_id The section ID
     * @param int $user_id The user ID
     * @param string $filter Optional SQL filter
     * @return array The user's data records
     */
    public function getUserData($section_id, $user_id, $filter = '')
    {
        $table_name = $this->getTableName($section_id);
        $table_id = $this->user_input->get_dataTable_id($table_name);

        if (!$table_id) {
            return [];
        }

        return $this->user_input->get_data_for_user($table_id, $user_id, $filter);
    }

    /**
     * Get data linked to a specific LLM message
     * 
     * @param int $section_id The section ID
     * @param int $message_id The message ID
     * @return array|null The data record, or null if not found
     */
    public function getDataByMessage($section_id, $message_id)
    {
        $table_name = $this->getTableName($section_id);
        $table_id = $this->user_input->get_dataTable_id($table_name);

        if (!$table_id) {
            return null;
        }

        $filter = "AND llm_message_id = '{$message_id}'";
        $data = $this->user_input->get_data($table_id, $filter, false, null, true);

        return $data ?: null;
    }

    /**
     * Get data linked to a specific conversation
     * 
     * @param int $section_id The section ID
     * @param int $conversation_id The conversation ID
     * @return array The data records for this conversation
     */
    public function getDataByConversation($section_id, $conversation_id)
    {
        $table_name = $this->getTableName($section_id);
        $table_id = $this->user_input->get_dataTable_id($table_name);

        if (!$table_id) {
            return [];
        }

        $filter = "AND llm_conversation_id = '{$conversation_id}'";
        return $this->user_input->get_data($table_id, $filter, false);
    }

    /**
     * Check if a section has a data table configured
     * 
     * @param int $section_id The section ID
     * @return bool True if data table exists
     */
    public function hasDataTable($section_id)
    {
        $table_name = $this->getTableName($section_id);
        return $this->user_input->get_dataTable_id($table_name) !== false;
    }

    /**
     * Get table info for a section
     * 
     * @param int $section_id The section ID
     * @return array|null Table info or null
     */
    public function getTableInfo($section_id)
    {
        $table_name = $this->getTableName($section_id);
        $table_id = $this->user_input->get_dataTable_id($table_name);

        if (!$table_id) {
            return null;
        }

        return $this->db->query_db_first(
            "SELECT * FROM dataTables WHERE id = ?",
            [$table_id]
        );
    }

    /**
     * Delete all data for a section
     * 
     * This soft-deletes all records in the section's data table.
     * Used when cleaning up or resetting a section's data.
     * 
     * @param int $section_id The section ID
     * @return bool Success status
     */
    public function deleteAllSectionData($section_id)
    {
        $table_name = $this->getTableName($section_id);
        $table_id = $this->user_input->get_dataTable_id($table_name);

        if (!$table_id) {
            return true; // No data to delete
        }

        try {
            // Soft delete all rows
            $this->db->execute_update_db(
                "UPDATE dataRows SET deleted = 1 WHERE id_dataTables = :table_id",
                [':table_id' => $table_id]
            );

            return true;
        } catch (Exception $e) {
            error_log("LlmDataSavingService: Error deleting section data: " . $e->getMessage());
            return false;
        }
    }
}
