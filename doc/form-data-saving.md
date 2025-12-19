# Form Data Saving to SelfHelp UserInput

## Overview

This feature allows LLM Chat form submissions to be saved to SelfHelp's UserInput system, enabling:
- Data persistence in the standard SelfHelp data tables
- Integration with SelfHelp's data export and reporting features
- Linking form responses to specific LLM messages for audit trails
- Support for both "log mode" (new entry per submission) and "record mode" (update existing record)

## Architecture

### SelfHelp UserInput Integration

The LLM plugin integrates with SelfHelp's standard `UserInput` service (`server/service/UserInput.php`), specifically using the `save_data()` function. This ensures:

1. **Consistent Data Storage**: Data is stored in the standard `dataTables`, `dataCols`, `dataRows`, and `dataCells` tables
2. **Dynamic Columns**: New form fields automatically create new columns in the data table
3. **Transaction Logging**: All operations are logged in the transactions table
4. **Cache Management**: UserInput handles cache clearing automatically
5. **Action Triggers**: Form submissions can trigger SelfHelp jobs and actions

### Data Table Naming

Each llmChat section gets its own data table with the naming convention:
```
llmChat_{section_id}
```

For example, section ID `123` creates table `llmChat_123`.

### Data Flow

```
User submits form → LlmChatController processes → LlmDataSavingService prepares data → 
UserInput::save_data() stores → Data saved to section's dataTable
```

## Configuration Fields

### llmChat Style Fields

| Field | Type | Description |
|-------|------|-------------|
| `enable_data_saving` | checkbox | Enable saving form data to SelfHelp UserInput |
| `data_table_name` | text | Display name for the data table (shown in admin) |
| `is_log` | checkbox | Data save mode: Enabled = Log Mode (new entry per submission), Disabled = Record Mode (update existing record for user) |

### Save Mode Behavior

- **Log Mode (is_log = true)**: Each form submission creates a new row in the data table. Useful for tracking submissions over time.
- **Record Mode (is_log = false)**: Updates the user's existing record if one exists, otherwise creates a new record. Useful for user profiles or preferences that should only have one entry per user.

### Form Field Mapping

When the LLM returns a form, field IDs are used as column names in the data table:

```json
{
  "type": "form",
  "fields": [
    {"id": "anxiety_level", "type": "radio", ...},
    {"id": "coping_strategies", "type": "checkbox", ...}
  ]
}
```

This creates columns:
- `anxiety_level` (text)
- `coping_strategies` (JSON array stored as text)

## Data Table Structure

### Automatic Initialization

When an llmChat section is loaded with `enable_data_saving` enabled, the system automatically:
1. Creates the dataTable if it doesn't exist
2. Updates the display name if configured
3. Creates columns dynamically as form fields are submitted

### Standard Columns

Every LLM Chat data table includes these columns (managed by UserInput):

| Column | Type | Description |
|--------|------|-------------|
| `id_users` | INT | User who submitted the form |
| `llm_message_id` | INT | Reference to the LLM message (optional) |
| `llm_conversation_id` | INT | Reference to the conversation |
| `timestamp` | TIMESTAMP | When the record was created |

### Dynamic Columns

Form field IDs become column names. The LLM should use descriptive, snake_case IDs:

**Good field IDs:**
- `anxiety_frequency`
- `primary_goal`
- `coping_strategies`

**Bad field IDs:**
- `q1` (not descriptive)
- `field-1` (contains hyphen - will be converted to underscore)
- `123` (starts with number - will be prefixed)

### Column Name Sanitization

Field IDs are automatically sanitized to valid column names:
- Converted to lowercase
- Invalid characters replaced with underscores
- Leading numbers/underscores removed
- Consecutive underscores collapsed
- Reserved names prefixed with `field_`

## Save Modes

### Log Mode (Default)

Each form submission creates a new row. Use cases:
- Assessment tracking over time
- Session logs
- Event recording
- Historical data collection

### Record Mode

Updates the user's existing record or creates if not exists. The update is based on `id_users`, so each user has one record that gets updated.

Use cases:
- User profiles
- Preferences
- Progress tracking (latest state)

## Implementation

### LlmDataSavingService

Located at `server/plugins/sh-shp-llm/server/service/LlmDataSavingService.php`:

```php
class LlmDataSavingService {
    /**
     * Save form data to UserInput system
     * 
     * @param int $section_id The llmChat section ID
     * @param int $user_id The user who submitted
     * @param array $form_values The form field values
     * @param array $form_definition Form definition (for metadata)
     * @param int|null $message_id Optional message ID to link
     * @param int|null $conversation_id Optional conversation ID
     * @param string $mode 'log' or 'record'
     * @return int|false The record ID or false on failure
     */
    public function saveFormData(
        $section_id,
        $user_id,
        $form_values,
        $form_definition = [],
        $message_id = null,
        $conversation_id = null,
        $mode = 'log'
    );
    
    /**
     * Initialize data table for a section
     * Called automatically when llmChat is loaded with data saving enabled
     */
    public function initializeDataTable($section_id, $display_name = '');
    
    /**
     * Update display name for a section's data table
     */
    public function updateTableDisplayName($section_id, $display_name);
    
    /**
     * Get user's data from a section's table
     */
    public function getUserData($section_id, $user_id, $filter = '');
    
    /**
     * Get data linked to a specific message
     */
    public function getDataByMessage($section_id, $message_id);
    
    /**
     * Get data linked to a specific conversation
     */
    public function getDataByConversation($section_id, $conversation_id);
}
```

### Integration with UserInput

The service delegates to `UserInput::save_data()` which handles:
- Table creation (if needed)
- Column creation (if needed)
- Data insertion or update
- Transaction logging
- Cache clearing
- Job/action triggering

## Usage Example

### CMS Configuration

1. Create an llmChat section
2. Enable "Save Form Data" checkbox
3. Set display name for the data table
4. Set save mode to "log" or "record"
5. Configure conversation context with form instructions

### Context Example

```markdown
# Anxiety Assessment Module

You are conducting an anxiety assessment. Use forms to collect data.

## Data Collection Guidelines
- Use descriptive field IDs (e.g., anxiety_frequency, not q1)
- Required fields: anxiety_frequency, anxiety_intensity, primary_goal
- Optional fields: triggers, coping_strategies

## Assessment Flow
1. Ask about frequency
2. Ask about intensity  
3. Ask about triggers
4. Ask about coping strategies
5. Ask about goals
```

### Data Access

Saved data can be accessed through:
- SelfHelp's Data Administration Interface
- AJAX Data Source API (`/ajax/data?table=llmChat_123`)
- Direct database queries
- Export functionality
- The `data_config` field in other components

## Continue Button in Form Mode

When form mode is enabled (`enable_form_mode`), the chat expects form submissions. If the LLM returns a response without a form (e.g., a summary or conclusion), a "Continue" button appears allowing the user to prompt the LLM to continue.

### Configuration

| Field | Type | Description |
|-------|------|-------------|
| `continue_button_label` | text | Label for the continue button (default: "Continue") |

### Behavior

1. Form mode enabled + last message is from assistant + no form in response → Show Continue button
2. User clicks Continue → Sends `continue_conversation` action to backend
3. Backend prompts LLM to continue with next step/form
4. LLM responds with next form or content

## Security Considerations

1. **User Isolation**: Users can only access their own data (enforced by UserInput)
2. **Input Validation**: Form values are sanitized before storage
3. **Column Names**: Field IDs are validated and sanitized
4. **Permissions**: Data access follows SelfHelp ACL rules
5. **Transaction Logging**: All operations are logged for audit

## Troubleshooting

### Table Not Created

Check that:
- `enable_data_saving` is enabled
- Section has been loaded at least once after enabling
- Database user has CREATE TABLE permissions

### Data Not Saving

Verify:
- Form submission is successful (check network tab)
- `enable_data_saving` is enabled
- No PHP errors in logs
- Check `LlmDataSavingService` error logs

### Column Names Different from Field IDs

Field IDs are sanitized to valid column names. Check the sanitization rules above.

### Data Not Linked to Messages

Ensure the form submission includes the message ID in the request. This is handled automatically by the frontend.
