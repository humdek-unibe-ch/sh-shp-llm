# Danger Keywords Examples and Guidelines

## Overview
This document provides examples of danger keywords that should trigger safety interventions in AI conversations. Keywords are organized by category, severity level, and language. These keywords are used by the LLM plugin's danger detection system to protect users in research and therapeutic contexts.

## How the System Works

### Double Protection Strategy
1. **Controller-Level Blocking**: Messages are scanned before AI processing. If danger keywords are detected, the message is blocked immediately.
2. **LLM Context Injection**: Danger keywords are injected into the AI's system context as critical, non-overridable safety instructions. This provides defense-in-depth.

### What Happens When Detected
- User's message is blocked (not sent to AI)
- User sees a supportive safety message
- Email notifications sent to configured addresses (via JobScheduler)
- Detection logged to transactions table for audit
- Conversation remains active (user can continue with different messages)

## Severity Levels

### Emergency (Highest Priority)
Immediate interruption + emergency notifications + potential emergency services contact
- **suicide**, **self-harm**, **kill myself**, **end my life**
- **terrorism**, **bomb**, **attack**, **mass shooting**
- **harm others**, **kill someone**, **murder**

### Critical (High Priority)
Immediate interruption + administrator notifications + urgent follow-up
- **abuse**, **rape**, **sexual assault**
- **violence**, **fight**, **threat**
- **drugs**, **overdose**, **addiction crisis**

### Warning (Medium Priority)
Log detection + continue with caution + optional notifications
- **depression**, **anxiety crisis**, **mental breakdown**
- **eating disorder**, **starvation**, **binge**
- **relationship abuse**, **domestic violence**

## Recommended Default Keywords

### Minimal Set (Recommended Starting Point)
This is the default set configured in the database. It covers the most critical safety scenarios:

```
suicide,selbstmord,kill myself,mich umbringen,self-harm,selbstverletzung,harm myself,mir schaden,end my life,mein leben beenden,overdose,überdosis,kill someone,jemanden töten,harm others,anderen schaden
```

### Extended Set (For Mental Health Applications)
For applications dealing with mental health, consider adding:

```
suicide,selbstmord,kill myself,mich umbringen,self-harm,selbstverletzung,harm myself,mir schaden,end my life,mein leben beenden,overdose,überdosis,kill someone,jemanden töten,harm others,anderen schaden,want to die,sterben wollen,no reason to live,kein grund zu leben,hurt myself,mich verletzen,cutting,ritzen,take my life,mir das leben nehmen
```

### Comprehensive Set (For High-Risk Contexts)
For therapeutic or crisis intervention contexts:

```
suicide,selbstmord,suicidal,suizidal,kill myself,mich umbringen,self-harm,selbstverletzung,harm myself,mir schaden,end my life,mein leben beenden,overdose,überdosis,kill someone,jemanden töten,harm others,anderen schaden,want to die,sterben wollen,no reason to live,kein grund zu leben,hurt myself,mich verletzen,cutting,ritzen,take my life,mir das leben nehmen,hopeless,hoffnungslos,worthless,wertlos,give up,aufgeben,can't go on,kann nicht mehr,better off dead,besser tot,end it all,allem ein ende,no way out,kein ausweg
```

## English Keywords by Category

### Mental Health Emergency
```
suicide,self-harm,suicidal thoughts,kill myself,end my life,harm myself,cut myself,overdose,want to die,no reason to live,better off dead,can't go on,hopeless,worthless,give up,end it all,no way out
```

### Violence and Threats
```
kill,kill someone,murder,attack,terrorism,bomb,explosive,weapon,shoot,stab,violence,fight,hurt,harm,threat,threaten,intimidate,assault,battery
```

### Sexual Harm
```
rape,sexual assault,molest,abuse,harass,stalk,exploit,traffick
```

### Substance Abuse Crisis
```
overdose,addiction crisis,drug emergency,alcohol poisoning,withdrawals
```

### Self-Destructive Behavior
```
starve myself,binge,purge,eating disorder,anorexia,bulimia,cutting,bruising,burning,self-injury,self-mutilation
```

## German Keywords by Category

### Mental Health Emergency (German)
```
selbstmord,selbstmordgedanken,mich umbringen,mein leben beenden,mir schaden zufügen,schneiden,schnitte,überdosis,sterben wollen,kein grund zu leben,besser tot,kann nicht mehr,hoffnungslos,wertlos,aufgeben,allem ein ende,kein ausweg,suizidal
```

### Violence and Threats (German)
```
töten,jemanden töten,mord,angriff,terrorismus,bombe,explosiv,waffe,schießen,erstechen,gewalt,kampf,verletzen,schädigen,drohung,bedrohen,einschüchtern,überfall,schlägerei
```

### Sexual Harm (German)
```
vergewaltigung,sexuelle gewalt,missbrauch,belästigen,stalken,ausbeuten,handel
```

### Substance Abuse Crisis (German)
```
überdosis,suchtkrise,drogennotfall,alkoholvergiftung,entziehung
```

## French Keywords

### Mental Health Emergency (French)
```
suicide,pensées suicidaires,me tuer,mettre fin à ma vie,me faire du mal,me couper,surconsommation,vouloir mourir,sans espoir,sans valeur,abandonner
```

### Violence and Threats (French)
```
tuer,tuer quelqu'un,meurtre,attaque,terrorisme,bombe,explosif,arme,tirer,poignarder,violence,bagarre,blesser,nuire,menace,menacer,intimider,agression,batterie
```

## Spanish Keywords

### Mental Health Emergency (Spanish)
```
suicidio,pensamientos suicidas,matarme,terminar mi vida,hacerme daño,cortarme,cortes,sobredosis,querer morir,sin esperanza,sin valor,rendirse
```

### Violence and Threats (Spanish)
```
matar,mata a alguien,asesinato,ataque,terrorismo,bomba,explosivo,arma,disparar,apuñalar,violencia,pelea,lastimar,dañar,amenaza,amenazar,intimidar,agresión,golpiza
```

## Configuration Examples

### Basic Configuration (CMS Field)
Enter keywords as a comma-separated list in the "Danger Keywords" field:

```
suicide,selbstmord,kill myself,mich umbringen,self-harm,harm myself,overdose,kill someone,harm others
```

### Multi-language Configuration
Include keywords in all languages your study uses:

```
suicide,selbstmord,suicidio,kill myself,mich umbringen,matarme,self-harm,selbstverletzung,autolesión,harm myself,mir schaden,hacerme daño
```

### Notification Emails Configuration
Enter email addresses in the "Danger Notification Emails" field (one per line or semicolon-separated):

```
researcher@university.edu
safety-team@university.edu
admin@example.com
```

Or:

```
researcher@university.edu; safety-team@university.edu; admin@example.com
```

## Implementation Guidelines

### Keyword Selection Principles
1. **Specificity**: Choose words that clearly indicate danger, not general emotional states
2. **Context Awareness**: Consider that some words might appear in safe contexts (e.g., "kill" in "kill time")
3. **Cultural Sensitivity**: Include culturally appropriate terms and phrases
4. **Regular Updates**: Review and update keyword lists based on emerging patterns
5. **Phrase Priority**: Use phrases like "kill myself" rather than single words like "kill" to reduce false positives

### False Positive Prevention
- Use phrases rather than single common words where possible
- The system uses word-boundary matching to avoid partial matches (e.g., "skill" won't match "kill")
- Consider the context of your specific application
- Allow administrators to review and adjust keywords based on false positive patterns

### Multi-language Support
- Maintain keywords in all languages your study uses
- Include common foreign words used in local contexts
- Support both native language keywords and English loanwords
- Consider transliteration for languages with different scripts

### Severity Calibration
- **Emergency**: Imminent danger to self or others, requires immediate intervention
- **Critical**: Serious risk requiring urgent professional attention
- **Warning**: Concerning content that should be monitored but may not require immediate action

### Cultural and Regional Considerations
- Adapt keywords based on local cultural contexts
- Include region-specific slang and colloquialisms
- Consider local emergency service integration
- Respect cultural differences in expressing distress

## Testing Scenarios

### Positive Test Cases (Should Trigger)
- "I want to kill myself"
- "I'm planning to harm myself"
- "There's a bomb in the building"
- "I feel like hurting someone"
- "Ich will mich umbringen"
- "Je veux me tuer"

### Negative Test Cases (Should NOT Trigger)
- "I want to kill time waiting"
- "The movie was harmful to my sleep schedule"
- "I'm planning to surprise my friend"
- "I feel like going for a run"
- "That joke killed me" (figurative language)
- "I'm dying to see that movie" (figurative language)

### Edge Cases
- Typos and variations ("suicde", "selfharm")
- Abbreviations ("SI" for self-injury)
- Emojis and special characters
- Code switching between languages

## Safety Message Examples

### Default Safety Message (English)
```markdown
I noticed some concerning content in your message. While I want to help, I'm not equipped to handle sensitive topics like this.

**Please consider reaching out to:**
- A trusted friend or family member
- A mental health professional
- Crisis hotlines in your area

If you're in immediate danger, please contact emergency services.

*Your well-being is important. Take care of yourself.*
```

### German Safety Message
```markdown
Ich habe einige besorgniserregende Inhalte in Ihrer Nachricht bemerkt. Obwohl ich Ihnen helfen möchte, bin ich nicht in der Lage, mit sensiblen Themen wie diesen umzugehen.

**Bitte erwägen Sie, sich an folgende Stellen zu wenden:**
- Eine vertrauenswürdige Person in Ihrem Umfeld
- Eine psychologische Fachkraft
- Krisenhotlines in Ihrer Nähe

Wenn Sie in unmittelbarer Gefahr sind, kontaktieren Sie bitte den Notdienst.

*Ihr Wohlbefinden ist wichtig. Passen Sie auf sich auf.*
```

### French Safety Message
```markdown
J'ai remarqué un contenu préoccupant dans votre message. Bien que je veuille vous aider, je ne suis pas équipé pour traiter des sujets sensibles comme celui-ci.

**Veuillez envisager de contacter:**
- Un ami ou un membre de la famille de confiance
- Un professionnel de la santé mentale
- Les lignes d'écoute de crise dans votre région

Si vous êtes en danger immédiat, veuillez contacter les services d'urgence.

*Votre bien-être est important. Prenez soin de vous.*
```

## Maintenance Procedures

### Regular Review Process
1. **Monthly Review**: Analyze detection patterns and false positives
2. **Quarterly Updates**: Add new emerging danger patterns
3. **Annual Audit**: Complete review of all keywords with experts
4. **Incident Response**: Immediate updates following critical incidents

### Performance Monitoring
- Track detection accuracy rates
- Monitor response times for danger detection
- Analyze false positive/negative rates
- Measure system performance impact

### Expert Consultation
- Regular consultation with mental health professionals
- Collaboration with crisis intervention specialists
- Input from law enforcement for security threats
- Cultural competency training for diverse contexts

## Legal and Ethical Considerations

### Data Protection
- Ensure danger detection data is properly encrypted
- Comply with data retention policies for sensitive content
- Implement proper access controls for detection logs
- Follow data minimization principles

### User Rights
- Clear communication about safety monitoring
- Right to opt-out where legally permissible
- Transparent procedures for false positive corrections
- Privacy protection in emergency situations

### Emergency Response
- Established protocols for emergency notifications
- Coordination with local emergency services
- Clear boundaries between platform responsibility and professional care
- Documentation of all emergency interventions

## Email Notification Template

The system sends email notifications using this format:

```markdown
# SAFETY ALERT - Danger Word Detection

A potentially dangerous keyword was detected in an LLM conversation.

## Detection Details

| Field | Value |
|-------|-------|
| **Detected Keywords** | {keywords} |
| **User ID** | {user_id} |
| **Conversation ID** | {conversation_id} |
| **Section ID** | {section_id} |
| **Detection Time** | {timestamp} |

## Message Excerpt

> {first 200 characters of message}

---

*This notification was sent by the SelfHelp LLM plugin danger detection system.*

*Please review the conversation and take appropriate action if needed.*

*The user has been shown a safety message and the conversation was blocked.*
```

## Audit Log Format

Detections are logged to the SelfHelp transactions table with this JSON structure:

```json
{
  "event": "danger_keyword_detected",
  "detected_keywords": ["suicide", "kill myself"],
  "user_message_excerpt": "I feel like...",
  "conversation_id": 12345,
  "section_id": 67890,
  "timestamp": "2025-12-23 10:30:00"
}
```

This allows administrators to:
- Filter transactions by event type
- Search for specific keywords
- Track patterns over time
- Generate reports for safety reviews
