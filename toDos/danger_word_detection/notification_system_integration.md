# Danger Word Detection - Notification System Integration

## Overview
Integration plan for connecting danger word detection with SelfHelp's notification and alerting systems to ensure timely response to critical safety incidents.

## Notification Architecture

### Notification Types

#### 1. Administrator Alerts (Internal)
- Immediate notifications to system administrators
- Detailed incident information with user context
- Escalation paths based on severity levels

#### 2. Emergency Services Integration (External)
- Automatic alerts to configured emergency contacts
- Structured data for emergency response systems
- Geographic location data when available

#### 3. User Notifications (Controlled)
- Appropriate user messaging based on severity
- Resource recommendations (hotlines, professional help)
- Clear next steps and support options

## Database Schema Extensions

### Enhanced Danger Detection Table
```sql
-- Extend llm_danger_detections table with notification tracking
ALTER TABLE `llm_danger_detections`
ADD COLUMN `notification_batch_id` VARCHAR(36) DEFAULT NULL,
ADD COLUMN `emergency_services_notified` BOOLEAN DEFAULT FALSE,
ADD COLUMN `emergency_service_reference` VARCHAR(255) DEFAULT NULL,
ADD COLUMN `notification_attempts` INT DEFAULT 0,
ADD COLUMN `last_notification_attempt` TIMESTAMP NULL,
ADD COLUMN `notification_response` TEXT DEFAULT NULL;
```

### Notification Templates Table
```sql
-- Templates for different notification types
CREATE TABLE IF NOT EXISTS `llm_danger_notification_templates` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `template_key` VARCHAR(50) UNIQUE NOT NULL,
    `severity_level` ENUM('warning', 'critical', 'emergency') NOT NULL,
    `notification_type` ENUM('admin_email', 'admin_sms', 'emergency_service', 'user_message') NOT NULL,
    `subject_template` TEXT DEFAULT NULL,
    `body_template` TEXT NOT NULL,
    `active` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default templates
INSERT INTO `llm_danger_notification_templates` (`template_key`, `severity_level`, `notification_type`, `subject_template`, `body_template`) VALUES
('admin_emergency_email', 'emergency', 'admin_email',
 'EMERGENCY: Danger Word Detection - Immediate Action Required',
 'Urgent danger word detection alert:

User ID: {{user_id}}
Username: {{username}}
Detected Keywords: {{keywords}}
Severity: {{severity}}
Message: {{message}}
Conversation ID: {{conversation_id}}
Detection Time: {{timestamp}}

Immediate action required. User may be in danger.'),

('admin_critical_email', 'critical', 'admin_email',
 'CRITICAL: Danger Word Detection - Urgent Review Needed',
 'Critical danger word detection alert:

User ID: {{user_id}}
Username: {{username}}
Detected Keywords: {{keywords}}
Severity: {{severity}}
Message: {{message}}
Conversation ID: {{conversation_id}}
Detection Time: {{timestamp}}

Please review and assess appropriate follow-up actions.'),

('emergency_service_alert', 'emergency', 'emergency_service',
 'URGENT: Potential Self-Harm/Suicide Risk',
 'EMERGENCY ALERT - AI Safety System Detection

Potential immediate danger detected in user communication.

User Details:
- User ID: {{user_id}}
- Username: {{username}}
- Location: {{location}}
- Contact Info: {{contact_info}}

Detected Content:
- Keywords: {{keywords}}
- Message Excerpt: {{message_excerpt}}
- Severity Level: {{severity}}

Detection Time: {{timestamp}}
System: SelfHelp AI Safety Monitor

Please assess and respond immediately.'),

('user_safety_message', 'warning', 'user_message',
 NULL,
 'I\'ve detected some content that concerns me for your safety. While I want to support you, I\'m not qualified to handle situations involving potential harm.

Please consider reaching out to:
- A trusted friend or family member
- A mental health professional
- Crisis hotlines in your area

If you\'re in immediate danger, please contact emergency services.

Your well-being is important. Take care of yourself.');

-- Add indexes
CREATE INDEX `idx_notification_templates_active` ON `llm_danger_notification_templates` (`active`);
CREATE INDEX `idx_notification_templates_type_severity` ON `llm_danger_notification_templates` (`notification_type`, `severity_level`);
```

### Emergency Contacts Configuration
```sql
-- System-level emergency contact configuration
CREATE TABLE IF NOT EXISTS `system_emergency_contacts` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `contact_name` VARCHAR(255) NOT NULL,
    `contact_type` ENUM('email', 'phone', 'api_endpoint', 'external_service') NOT NULL,
    `contact_value` VARCHAR(500) NOT NULL,
    `active` BOOLEAN DEFAULT TRUE,
    `priority` INT DEFAULT 1,
    `notification_types` SET('emergency', 'critical') DEFAULT 'emergency',
    `last_tested` TIMESTAMP NULL,
    `test_status` ENUM('untested', 'success', 'failed') DEFAULT 'untested',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default emergency contacts
INSERT INTO `system_emergency_contacts` (`contact_name`, `contact_type`, `contact_value`, `notification_types`) VALUES
('System Administrator', 'email', 'admin@yourdomain.com', 'emergency,critical'),
('Crisis Hotline Integration', 'api_endpoint', 'https://api.crisishotline.org/alert', 'emergency'),
('Emergency Services', 'phone', '+1-911', 'emergency');
```

## Service Integration

### DangerNotificationService Enhancement

```php
class DangerNotificationService {
    public function __construct($services) {
        $this->services = $services;
        $this->notificationTemplates = $this->loadNotificationTemplates();
        $this->emergencyContacts = $this->loadEmergencyContacts();
    }

    public function triggerNotifications($detectionId, $detectedWords, $severity, $userId) {
        $detectionData = $this->getDetectionData($detectionId);
        $userData = $this->getUserData($userId);

        $notificationBatchId = $this->generateBatchId();

        // Update detection record with batch ID
        $this->updateDetectionBatchId($detectionId, $notificationBatchId);

        // Send notifications based on severity and configuration
        $this->sendAdminNotifications($detectionData, $userData, $severity, $notificationBatchId);
        $this->sendEmergencyNotifications($detectionData, $userData, $severity, $notificationBatchId);
        $this->sendUserNotifications($detectionData, $userData, $severity);

        return $notificationBatchId;
    }

    private function sendEmergencyNotifications($detectionData, $userData, $severity, $batchId) {
        if ($severity !== 'emergency') {
            return;
        }

        $emergencyContacts = $this->getActiveEmergencyContacts();

        foreach ($emergencyContacts as $contact) {
            try {
                $this->sendEmergencyAlert($contact, $detectionData, $userData, $batchId);
                $this->logNotificationAttempt($batchId, $contact['id'], 'success');
            } catch (Exception $e) {
                $this->logNotificationAttempt($batchId, $contact['id'], 'failed', $e->getMessage());
            }
        }
    }
}
```

### Notification Templates System

```php
class NotificationTemplateService {
    public function renderTemplate($templateKey, $data) {
        $template = $this->getTemplate($templateKey);

        if (!$template) {
            throw new Exception("Template not found: $templateKey");
        }

        $subject = $this->renderString($template['subject_template'], $data);
        $body = $this->renderString($template['body_template'], $data);

        return [
            'subject' => $subject,
            'body' => $body,
            'type' => $template['notification_type']
        ];
    }

    private function renderString($template, $data) {
        // Simple template rendering with {{variable}} syntax
        foreach ($data as $key => $value) {
            $template = str_replace("{{{$key}}}", $value, $template);
        }
        return $template;
    }
}
```

## Integration Points

### 1. LLM Plugin Hooks Integration
```php
// In LlmHooks.php
public function hook_danger_word_detected($args) {
    $detectionId = $args['detection_id'];
    $severity = $args['severity'];
    $userId = $args['user_id'];

    // Trigger notification system
    $notificationService = new DangerNotificationService($this->services);
    $batchId = $notificationService->triggerNotifications($detectionId, $args['detected_words'], $severity, $userId);

    // Log hook execution
    $this->logHookExecution('danger_word_detected', $args, $batchId);

    return $args;
}
```

### 2. Admin Interface Integration
```php
// In AdminController.php
public function manageDangerNotificationTemplates() {
    $this->requireAdminAccess();

    $templates = $this->getDangerNotificationTemplates();

    return $this->render('admin/danger_notifications', [
        'templates' => $templates,
        'emergency_contacts' => $this->getEmergencyContacts()
    ]);
}

public function updateNotificationTemplate() {
    $this->requireAdminAccess();

    $templateId = $this->getPostData('template_id');
    $templateData = $this->getPostData('template_data');

    // Validate template data
    $this->validateTemplateData($templateData);

    // Update template
    $this->updateNotificationTemplate($templateId, $templateData);

    // Clear relevant caches
    $this->cache->invalidateCategory(CATEGORY_NOTIFICATION_TEMPLATES);

    return $this->successResponse('Template updated successfully');
}
```

### 3. System Monitoring Integration
```php
// In SystemMonitor.php
public function checkDangerDetectionHealth() {
    $alerts = [];

    // Check notification queue health
    $pendingNotifications = $this->getPendingNotifications();
    if ($pendingNotifications > 100) {
        $alerts[] = "High number of pending danger notifications: $pendingNotifications";
    }

    // Check emergency contact test status
    $failedContacts = $this->getFailedEmergencyContacts();
    if (!empty($failedContacts)) {
        $alerts[] = "Failed emergency contacts detected: " . implode(', ', $failedContacts);
    }

    // Check detection response times
    $avgResponseTime = $this->getAverageNotificationResponseTime();
    if ($avgResponseTime > 300) { // 5 minutes
        $alerts[] = "Slow danger notification response time: {$avgResponseTime}s";
    }

    return $alerts;
}
```

## API Integration

### Notification API Endpoints

#### GET /api/admin/danger-detections
```json
{
    "status": 200,
    "message": "OK",
    "data": {
        "detections": [
            {
                "id": 123,
                "user_id": "0000000123",
                "username": "user123",
                "detected_keywords": ["suicide", "harm"],
                "severity": "emergency",
                "timestamp": "2024-01-01 12:00:00",
                "notification_sent": true,
                "admin_response": "Contacted user, referred to crisis services"
            }
        ],
        "pagination": {
            "page": 1,
            "limit": 50,
            "total": 150
        }
    }
}
```

#### POST /api/admin/danger-detection/{id}/respond
```json
{
    "admin_response": "Contacted user and emergency services. User connected with crisis counselor.",
    "follow_up_actions": "Schedule follow-up check in 24 hours",
    "close_incident": true
}
```

#### GET /api/admin/notification-templates
Returns available notification templates for configuration.

## Emergency Services Integration

### API-Based Emergency Services
```php
class EmergencyServiceIntegration {
    public function sendEmergencyAlert($contact, $detectionData, $userData) {
        $endpoint = $contact['contact_value'];

        $payload = [
            'alert_type' => 'ai_safety_detection',
            'severity' => $detectionData['severity_level'],
            'user_info' => [
                'id' => $userData['id'],
                'name' => $userData['name'],
                'contact' => $userData['email']
            ],
            'detection_info' => [
                'keywords' => $detectionData['detected_keywords'],
                'message_excerpt' => $this->getSafeExcerpt($detectionData['user_message']),
                'timestamp' => $detectionData['detection_timestamp']
            ],
            'system_info' => [
                'system' => 'SelfHelp AI Safety Monitor',
                'version' => SYSTEM_VERSION
            ]
        ];

        return $this->sendHttpRequest($endpoint, $payload);
    }

    private function getSafeExcerpt($message) {
        // Return safe excerpt without full dangerous content
        return substr($message, 0, 100) . '...';
    }
}
```

### Phone/SMS Emergency Alerts
```php
class SmsEmergencyService {
    public function sendEmergencySms($phoneNumber, $detectionData, $userData) {
        $message = $this->buildEmergencySmsMessage($detectionData, $userData);

        // Use configured SMS gateway
        return $this->smsGateway->sendSms($phoneNumber, $message);
    }

    private function buildEmergencySmsMessage($detectionData, $userData) {
        return sprintf(
            "EMERGENCY: AI Safety Alert - User %s (ID: %s) detected %s keywords. Immediate attention required.",
            $userData['name'],
            $userData['id'],
            strtoupper($detectionData['severity_level'])
        );
    }
}
```

## Testing and Validation

### Notification Testing Scenarios
- [ ] Emergency notifications sent immediately
- [ ] Critical notifications batched appropriately
- [ ] Warning notifications logged but not urgent
- [ ] Template rendering works correctly
- [ ] Emergency service integration functions
- [ ] Admin interface shows notifications
- [ ] User receives appropriate safety messages

### Integration Testing
- [ ] Full detection-to-notification workflow
- [ ] Multi-channel notification delivery
- [ ] Error handling for failed notifications
- [ ] Retry logic for failed deliveries
- [ ] Rate limiting prevents notification spam
- [ ] Geographic routing for local emergency services

### Performance Testing
- [ ] High-volume detection scenarios
- [ ] Notification queue processing speed
- [ ] Database performance with audit logs
- [ ] API response times for emergency endpoints

## Compliance and Legal Considerations

### Data Protection
- [ ] Notification data encrypted in transit and at rest
- [ ] Minimum data retention for sensitive detection logs
- [ ] User consent for emergency data sharing
- [ ] GDPR compliance for EU users
- [ ] HIPAA compliance for health-related data

### Emergency Response Protocols
- [ ] Clear boundaries of platform responsibility
- [ ] Coordination procedures with emergency services
- [ ] Documentation requirements for incidents
- [ ] Regular training for administrators
- [ ] Legal review of emergency notification templates

### Audit and Reporting
- [ ] Complete audit trail of all notifications
- [ ] Regular reporting on detection effectiveness
- [ ] Incident response time tracking
- [ ] False positive/negative rate monitoring
- [ ] Annual compliance audits



