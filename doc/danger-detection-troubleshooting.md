# Danger Word Detection - Troubleshooting & Testing Guide

## Quick Setup Checklist

To enable and test danger word detection, follow these steps:

### 1. Enable Danger Detection in CMS

1. Go to your llmChat component in the SelfHelp CMS
2. Find the **"Enable Danger Detection"** checkbox
3. ✅ **Check it to enable**
4. Click Save

**Default:** Disabled (unchecked) - you must enable it manually

### 2. Configure Danger Keywords

In the **"Danger Keywords"** field, enter keywords (comma-separated):

```
suicide,selbstmord,kill myself,mich umbringen,self-harm,selbstverletzung,harm myself,mir schaden,end my life,mein leben beenden
```

**Tips:**
- Use phrases like "kill myself" instead of just "kill" to reduce false positives
- Include variations in all languages your study uses
- Keywords are case-insensitive
- Word-boundary matching prevents "skill" from matching "kill"

### 3. Configure Notification Emails

In the **"Danger Notification Emails"** field, enter email addresses (one per line or semicolon-separated):

```
researcher@university.edu
safety-team@university.edu
admin@example.com
```

**Note:** If this field is empty, NO emails will be sent (only audit logging)

### 4. Test the System

**Test Message:** `i want to kill myself`

**Expected Behavior:**
1. Message is blocked before reaching AI
2. User sees safety message
3. Conversation is blocked (user must start new conversation)
4. Emails sent to configured addresses
5. Detection logged to transactions table

## Troubleshooting Common Issues

### Issue 1: Safety Message Truncated

**Problem:** Message appears cut off: "I noticed some concerning content in your message. While I want to help, I'm not equipped to handle why cut?"

**Causes & Solutions:**

1. **Check the Default Value**
   - Go to CMS → Fields → `danger_blocked_message`
   - Verify the full default text is present
   - Text should include "sensitive topics like this" (not cut off)

2. **Check Database Field**
   - Ensure `danger_blocked_message` is type `markdown` (not `text`)
   - Markdown fields support longer content

3. **Browser Console**
   - Open Developer Tools → Console
   - Check for JavaScript errors during message display

### Issue 2: No Emails Sent

**Problem:** Danger keywords detected but no emails received

**Debug Steps:**

1. **Check Configuration**
   ```
   - Is "Enable Danger Detection" checked? ✅
   - Are emails configured in "Danger Notification Emails"? ✅
   - Are emails valid format (name@domain.com)? ✅
   ```

2. **Check Server Logs**
   Look for these log messages in your PHP error log:
   ```
   LLM Danger Detection: Notification check - Keywords: suicide, kill myself
   LLM Danger Detection: Configured emails: researcher@university.edu
   LLM Danger Detection: Attempting to send email to: researcher@university.edu
   LLM Danger Detection: Email sent successfully to: researcher@university.edu
   ```

3. **If logs show "No notification emails configured":**
   - Emails field is empty or not saved
   - Re-enter emails and click Save
   - Refresh the page and check again

4. **If logs show "Failed to send email":**
   - Check SelfHelp mail configuration
   - Test SelfHelp's email system with another feature
   - Check server mail logs (`/var/log/mail.log` or similar)

5. **Check Job Scheduler**
   - Go to SelfHelp Admin → Scheduled Jobs
   - Look for jobs with description: "Danger keyword detection notification"
   - Status should be "done" (not "queued" or "failed")

### Issue 3: LLM Still Responding (Not Blocked)

**Problem:** LLM responds with advice instead of blocking the message

**Causes & Solutions:**

1. **Danger Detection Not Enabled**
   - Verify checkbox is checked in CMS
   - Default is disabled - must be manually enabled

2. **Keyword Not Matched**
   - Check exact keyword: "kill myself" ≠ "kill myslef" (typo)
   - Current version requires exact match (typo tolerance is basic)
   - Add common variations: `kill myself,kil myself,kill myslef`

3. **Phrase vs Word Matching**
   - Single words use word-boundary: `kill` matches `I kill`
   - Phrases: `kill myself` must appear as complete phrase
   - Typos in phrases may not match

4. **Check Server Logs**
   Look for:
   ```
   LLM Danger Detection: Scanning message for keywords
   LLM Danger Detection: Message (first 100 chars): i want to kill myself
   LLM Danger Detection: Keywords to check: suicide, kill myself, ...
   LLM Danger Detection: DETECTED keyword: kill myself
   ```

   If you see "No keywords detected" but expected detection:
   - Keyword list might be empty (check CMS configuration)
   - Typo in user message doesn't match configured keyword
   - Add the variation to keyword list

### Issue 4: Conversation Not Blocked

**Problem:** User can continue sending messages after detection

**This is now FIXED in latest version:**
- Conversations are automatically blocked when danger keywords detected
- User must start a new conversation
- Blocked conversations don't appear in conversation list
- Attempting to send messages to blocked conversation returns error

**Check:**
1. Database field `llmConversations.blocked` should be `1`
2. Field `blocked_reason` should show detected keywords
3. User's conversation list should not show blocked conversation

## Checking Audit Logs

### View Detection Logs

**In Database:**
```sql
SELECT * FROM transactions 
WHERE table_name = 'llm_danger_detection' 
ORDER BY timestamp DESC 
LIMIT 10;
```

**Log Format:**
```json
{
  "event": "danger_keyword_detected",
  "detected_keywords": ["suicide", "kill myself"],
  "user_message_excerpt": "i want to...",
  "conversation_id": 12345,
  "section_id": 67890,
  "timestamp": "2025-12-23 14:30:00"
}
```

### Check Blocked Conversations

```sql
SELECT id, id_users, title, blocked, blocked_reason, blocked_at
FROM llmConversations
WHERE blocked = 1
ORDER BY blocked_at DESC;
```

## Testing Scenarios

### Test 1: Basic Detection

**Message:** `i want to kill myself`

**Expected:**
- ✅ Detected (matches "kill myself")
- ✅ Blocked
- ✅ Safety message shown
- ✅ Email sent
- ✅ Conversation blocked

### Test 2: German Detection

**Message:** `ich will mich umbringen`

**Expected:**
- ✅ Detected (matches "mich umbringen")
- ✅ Same behavior as Test 1

### Test 3: Phrase Variation

**Message:** `i want to harm myself`

**Expected:**
- ✅ Detected (matches "harm myself")
- ✅ Blocked

### Test 4: False Positive Prevention

**Message:** `i want to kill time`

**Expected:**
- ❌ NOT detected ("kill time" is not a danger phrase)
- ✅ Normal AI response

### Test 5: Typo Handling

**Message:** `i want to kil myself` (missing 'l')

**Expected (current version):**
- ❌ May NOT detect (exact match required)
- **Solution:** Add variations to keyword list: `kill myself,kil myself`

**Future Enhancement:** Fuzzy matching with Levenshtein distance

## Email Notification Format

When danger keywords are detected, notifications are sent with:

**Subject:** `[SAFETY ALERT] Danger keyword detected in LLM conversation`

**Body:**
```
# SAFETY ALERT - Danger Word Detection

A potentially dangerous keyword was detected in an LLM conversation.

## Detection Details

| Field | Value |
|-------|-------|
| **Detected Keywords** | kill myself, suicide |
| **User ID** | 0000000123 |
| **Conversation ID** | 0000012345 |
| **Section ID** | 0000000042 |
| **Detection Time** | 2025-12-23 14:30:00 |

## Message Excerpt

> i want to kill myself...

---

*This notification was sent by the SelfHelp LLM plugin danger detection system.*
```

## Configuration Best Practices

### Keyword Selection

✅ **DO:**
- Use specific phrases: `kill myself`, `harm myself`
- Include multi-language variants
- Test with real user input patterns
- Review and update quarterly

❌ **DON'T:**
- Use single common words: `sad`, `bad`, `hurt` (too many false positives)
- Rely only on English keywords for international studies
- Forget to test after updates

### Email Configuration

✅ **DO:**
- Configure at least 2 notification addresses
- Use group emails or distribution lists
- Test email delivery before going live
- Document who receives notifications

❌ **DON'T:**
- Leave email field empty (no notifications!)
- Use personal emails that might change
- Forget to check spam folders

### Safety Messages

✅ **DO:**
- Be supportive and non-judgmental
- Provide specific resources (hotlines, contacts)
- Localize for your study's language
- Include emergency contact info

❌ **DON'T:**
- Be alarmist or scary
- Provide generic "seek help" without specifics
- Make users feel bad for triggering detection
- Forget to mention they can continue chatting

## Advanced Features

### Conversation Blocking

**Automatic:**
- When danger keywords detected
- `llmConversations.blocked` set to `1`
- `blocked_reason` contains keywords
- `blocked_at` timestamp recorded
- `blocked_by` is `NULL` (automatic)

**Manual (via Admin Panel - coming soon):**
- Admin can manually block conversations
- `blocked_by` contains admin user ID
- Custom reason can be entered

### Soft Delete

Conversations can be marked as deleted without removing from database:
- `llmConversations.deleted` = `1`
- Hidden from user's conversation list
- Preserved for audit/research
- Separate from blocking (both flags independent)

### Admin Panel Features (Coming Soon)

- View all detected incidents
- Block/unblock conversations manually
- Review detection patterns
- Export audit logs
- Mark conversations as deleted (soft delete)

## FAQ

**Q: Can users bypass detection with typos?**
A: Currently, yes. Add common typo variations to keyword list. Future versions will include fuzzy matching.

**Q: Are conversations permanently blocked?**
A: Automatically blocked conversations remain blocked. Admin panel (coming soon) will allow unblocking.

**Q: What happens to blocked conversations?**
A: They're hidden from user's list, preserved in database, and cannot receive new messages.

**Q: Can I test without sending real emails?**
A: Yes, leave email field empty. Detection still works, only audit logging occurs.

**Q: How do I know if it's working?**
A: Check server logs for "LLM Danger Detection:" messages. Enable PHP error logging.

**Q: Does this work in form mode?**
A: Yes, form submissions are also scanned for danger keywords.

**Q: Does this work in streaming mode?**
A: Yes, detection happens during preparation phase before streaming starts.

## Support

If you encounter issues not covered here:

1. Check server error logs (`/var/log/php-fpm/error.log` or similar)
2. Check database table `llmConversations` for blocked conversations
3. Review `transactions` table for detection logs
4. Verify SelfHelp's email system works (test with password reset)
5. Contact SelfHelp support with log excerpts

---

**Version:** 1.0.0
**Last Updated:** December 23, 2025

