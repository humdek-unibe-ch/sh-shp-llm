# Danger Word Detection System - Implementation Plan

## Overview
Implement a comprehensive danger word detection system that monitors AI conversations for potentially harmful content, breaks AI interactions when detected, and triggers appropriate notifications. This is a critical safety feature to protect users and comply with ethical AI guidelines.

## Requirements
- Add configurable danger keywords field to page properties (global system setting)
- Real-time detection of danger keywords in user messages before AI processing
- Immediate interruption of AI conversation flow when danger words detected
- Trigger notification system for administrators/researchers
- Support for multi-language danger word lists
- Configurable severity levels (warning, critical, emergency)
- Audit trail of all danger word detections
- Graceful user handling with appropriate messaging
- Optional user consent for emergency contact procedures

## Database Changes

### 1. Add Danger Keywords Field to System Settings
```sql
-- Add danger keywords field (global system setting)
INSERT IGNORE INTO `fields` (`id`, `name`, `id_type`, `display`) VALUES
(NULL, 'danger_keywords', get_field_type_id('textarea'), '1');

-- Link to page settings (global configuration)
INSERT IGNORE INTO `pages_fields` (`id_pages`, `id_fields`, `default_value`, `help`) VALUES
((SELECT id FROM pages WHERE keyword = 'admin_settings'), get_field_id('danger_keywords'),
'suicide,self-harm,harm,kill,death,die,terrorism,bomb,attack,violence,threat',
'Comma-separated list of danger keywords that trigger safety interventions. Case-insensitive. Supports multi-language entries.');
```

### 2. Create Danger Detection Audit Table
```sql
-- Table for tracking danger word detections
CREATE TABLE IF NOT EXISTS `llm_danger_detections` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `id_users` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `id_llm_conversations` INT DEFAULT NULL,
    `detected_keywords` TEXT NOT NULL,
    `user_message` TEXT NOT NULL,
    `severity_level` ENUM('warning', 'critical', 'emergency') DEFAULT 'warning',
    `detection_timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `ai_interrupted` BOOLEAN DEFAULT TRUE,
    `notification_sent` BOOLEAN DEFAULT FALSE,
    `admin_response` TEXT DEFAULT NULL,
    `admin_response_timestamp` TIMESTAMP NULL,
    `follow_up_actions` TEXT DEFAULT NULL,
    CONSTRAINT `fk_danger_detection_users` FOREIGN KEY (`id_users`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_danger_detection_conversation` FOREIGN KEY (`id_llm_conversations`) REFERENCES `llmConversations`(`id`)
);

-- Add indexes for performance
CREATE INDEX `idx_danger_detections_user` ON `llm_danger_detections` (`id_users`);
CREATE INDEX `idx_danger_detections_timestamp` ON `llm_danger_detections` (`detection_timestamp`);
CREATE INDEX `idx_danger_detections_severity` ON `llm_danger_detections` (`severity_level`);
```

### 3. Add Danger Keywords to Page Configuration
```sql
-- Add danger keywords field to pages table for page-specific overrides
ALTER TABLE `pages` ADD COLUMN `danger_keywords_override` TEXT DEFAULT NULL;
```

## Code Changes

### 4. Create DangerDetectionService.php
Create a new service to handle danger word detection logic:

```php
class DangerDetectionService {
    private $dangerKeywords = [];
    private $severityMapping = [];

    public function __construct($services) {
        $this->services = $services;
        $this->loadDangerKeywords();
    }

    public function detectDangerWords($message, $userId, $conversationId = null) {
        $detectedWords = $this->scanMessageForDangerWords($message);

        if (!empty($detectedWords)) {
            return $this->handleDangerDetection($detectedWords, $message, $userId, $conversationId);
        }

        return ['safe' => true];
    }

    private function scanMessageForDangerWords($message) {
        $message = strtolower($message);
        $detected = [];

        foreach ($this->dangerKeywords as $keyword => $severity) {
            if (strpos($message, strtolower($keyword)) !== false) {
                $detected[$keyword] = $severity;
            }
        }

        return $detected;
    }

    private function handleDangerDetection($detectedWords, $message, $userId, $conversationId) {
        // Determine highest severity
        $severity = $this->getHighestSeverity($detectedWords);

        // Log detection
        $detectionId = $this->logDangerDetection($detectedWords, $message, $userId, $conversationId, $severity);

        // Trigger notifications
        $this->triggerNotifications($detectionId, $detectedWords, $severity, $userId);

        return [
            'safe' => false,
            'severity' => $severity,
            'detected_words' => array_keys($detectedWords),
            'detection_id' => $detectionId,
            'action_required' => $this->requiresImmediateAction($severity)
        ];
    }
}
```

### 5. Update LlmchatController.php
Add danger word checking before AI processing:

```php
public function handleMessageSubmission() {
    $message = $this->getUserMessage();
    $userId = $this->getCurrentUserId();
    $conversationId = $this->getConversationId();

    // CRITICAL: Check for danger words BEFORE any AI processing
    $dangerCheck = $this->dangerDetectionService->detectDangerWords($message, $userId, $conversationId);

    if (!$dangerCheck['safe']) {
        return $this->handleDangerWordDetection($dangerCheck);
    }

    // Continue with normal AI processing
    return $this->processAiMessage($message, $conversationId);
}

private function handleDangerWordDetection($dangerResult) {
    // Interrupt AI conversation
    $this->interruptConversation($dangerResult['detection_id']);

    // Return safe response to user
    return $this->getDangerResponse($dangerResult['severity']);
}
```

### 6. Update LlmHooks.php
Add danger word detection to message processing hooks:

```php
public function hook_llm_message_preprocessing($args) {
    $message = $args['message'];
    $userId = $args['user_id'];
    $conversationId = $args['conversation_id'];

    $dangerService = new DangerDetectionService($this->services);
    $dangerCheck = $dangerService->detectDangerWords($message, $userId, $conversationId);

    if (!$dangerCheck['safe']) {
        // Override the message processing
        $args['interrupt_processing'] = true;
        $args['danger_detection'] = $dangerCheck;

        // Log the interruption
        $this->logAiInterruption($dangerCheck['detection_id'], $conversationId);
    }

    return $args;
}
```

### 7. Create DangerNotificationService.php
Handle notifications for danger word detections:

```php
class DangerNotificationService {
    public function triggerNotifications($detectionId, $detectedWords, $severity, $userId) {
        // Get admin users to notify
        $adminUsers = $this->getAdminUsersForNotifications();

        // Create notification content
        $notificationData = $this->prepareNotificationData($detectionId, $detectedWords, $severity, $userId);

        // Send notifications based on severity
        switch ($severity) {
            case 'emergency':
                $this->sendEmergencyNotifications($adminUsers, $notificationData);
                $this->triggerSystemAlerts($notificationData);
                break;
            case 'critical':
                $this->sendCriticalNotifications($adminUsers, $notificationData);
                break;
            case 'warning':
                $this->sendWarningNotifications($adminUsers, $notificationData);
                break;
        }

        // Update detection record
        $this->markNotificationSent($detectionId);
    }
}
```

### 8. Update Admin Interface
Add danger word management to admin settings:

```php
// In AdminSettingsController.php
public function updateDangerKeywords() {
    $keywords = $this->getPostData('danger_keywords');

    // Validate keywords format
    if (!$this->validateKeywordFormat($keywords)) {
        return $this->errorResponse('Invalid keyword format');
    }

    // Update system setting
    $this->updateSystemSetting('danger_keywords', $keywords);

    // Clear relevant caches
    $this->cache->invalidateCategory(CATEGORY_SYSTEM_SETTINGS);

    return $this->successResponse('Danger keywords updated successfully');
}
```

## Danger Word Format Support

### Keyword Format
```
keyword1,keyword2,keyword3:severity
```

Examples:
```
suicide:emergency,self-harm:critical,harm:warning,violence:critical
```

### Multi-language Support
```
# English keywords
suicide,self-harm,harm,kill,death,die

# German keywords
selbstmord,selbstverletzung,schaden,töten,tod,sterben

# French keywords
suicide,auto-mutilation,mal,tuer,mort,mourir
```

### Severity Levels
- **warning**: Log detection, continue conversation with caution
- **critical**: Interrupt conversation, notify administrators
- **emergency**: Interrupt conversation, trigger immediate alerts, consider emergency protocols

## Implementation Steps

1. **Database Setup**: Add danger keywords field and audit table
2. **Core Services**: Create DangerDetectionService and DangerNotificationService
3. **Controller Integration**: Add danger checking to LlmchatController
4. **Hook Integration**: Add preprocessing hooks to LlmHooks
5. **Admin Interface**: Add danger word management to admin settings
6. **Notification System**: Implement notification triggers and templates
7. **Testing**: Comprehensive testing with various scenarios
8. **Documentation**: Update README and add safety guidelines

## Files to Modify
- `server/plugins/sh-shp-llm/server/db/v1.0.0.sql` - Database schema
- `server/plugins/sh-shp-llm/server/service/DangerDetectionService.php` - *NEW*
- `server/plugins/sh-shp-llm/server/service/DangerNotificationService.php` - *NEW*
- `server/plugins/sh-shp-llm/server/component/style/llmchat/LlmchatController.php`
- `server/plugins/sh-shp-llm/server/component/LlmHooks.php`
- `server/plugins/sh-shp-llm/server/component/style/admin/AdminSettingsController.php` - *NEW/UPDATE*
- `server/plugins/sh-shp-llm/README.md` - Documentation

## Safety Response Messages

### Warning Level
*"I notice you mentioned some concerning topics. While I want to help, I'm not equipped to handle sensitive issues like this. Please consider speaking with a qualified professional or trusted person in your life."*

### Critical Level
*"I've detected some content that requires immediate attention. For your safety, I'm pausing this conversation and notifying the appropriate support team. Please seek help from qualified professionals."*

### Emergency Level
*"This appears to be an emergency situation. I'm immediately notifying emergency services and our crisis support team. Please stay safe and seek immediate help if you're in danger."*

## Testing Checklist
- [ ] Danger word detection works for various keywords
- [ ] Different severity levels trigger appropriate responses
- [ ] AI conversation properly interrupted when danger detected
- [ ] Notifications sent to administrators
- [ ] Audit trail properly logged
- [ ] Multi-language keyword support
- [ ] Case-insensitive detection
- [ ] Partial word matching doesn't cause false positives
- [ ] Admin interface for managing danger keywords
- [ ] Backwards compatibility maintained
- [ ] Performance impact minimal
- [ ] Edge cases handled (empty messages, special characters)

## Security Considerations
- [ ] Input sanitization for danger keywords
- [ ] Access control for danger word management (admin only)
- [ ] Secure logging of sensitive content
- [ ] Rate limiting for notifications to prevent abuse
- [ ] Encryption of sensitive detection data if needed
- [ ] Regular security audits of danger word configurations

## Performance Optimization
- [ ] Cache danger keyword lists
- [ ] Efficient string matching algorithms
- [ ] Minimal processing overhead for safe messages
- [ ] Batch notification processing for high-volume scenarios

## Legal and Ethical Compliance
- [ ] Compliance with data protection regulations
- [ ] Clear user communication about safety monitoring
- [ ] Professional emergency response procedures
- [ ] Regular review and updating of danger keywords
- [ ] Collaboration with mental health professionals for keyword selection




