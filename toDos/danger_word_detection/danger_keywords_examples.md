# Danger Keywords Examples and Guidelines

## Overview
This document provides examples of danger keywords that should trigger safety interventions in AI conversations. Keywords are organized by category, severity level, and language.

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

## English Keywords

### Mental Health Emergency
```
suicide,self-harm,suicidal thoughts,kill myself,end my life,harm myself,cut myself,overdose
```

### Violence and Threats
```
kill,kill someone,murder,attack,terrorism,bomb,explosive,weapon,shoot,stab
violence,fight,hurt,harm,threat,threaten,intimidate,assault,battery
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
starve myself,binge,purge,eating disorder,anorexia,bulimia
cutting,bruising,burning,self-injury,self-mutilation
```

## German Keywords

### Mental Health Emergency (German)
```
selbstmord,selbstmordgedanken,mich umbringen,mein leben beenden,mir schaden,zufuegen,schneiden,schnitte,ueberdosis
```

### Violence and Threats (German)
```
toeten,jemanden toeten,mord,angriff,terrorismus,bombe,explosiv,waffe,schiessen,erstechen
gewalt,kampf,verletzen,schaedigen,drohung,bedrohen,einschuechtern,ueberfall,schlaegerei
```

### Sexual Harm (German)
```
vergewaltigung,sexuelle gewalt,missbrauch,belästigen,stalken,ausbeuten,handel
```

### Substance Abuse Crisis (German)
```
ueberdosis,suchtkrise,drogennotfall,alkoholvergiftung,entziehung
```

## French Keywords

### Mental Health Emergency (French)
```
suicide,pensées suicidaires,me tuer,mettre fin à ma vie,me faire du mal,me couper,surconsommation
```

### Violence and Threats (French)
```
tuer,tuer quelqu'un,meurtre,attaque,terrorisme,bombe,explosif,arme,tirer,poignarder
violence,bagarre,blesser,nuire,menace,menacer,intimider,agression,batterie
```

## Spanish Keywords

### Mental Health Emergency (Spanish)
```
suicidio,pensamientos suicidas,matarme,terminar mi vida,hacerme daño,cortarme,cortes,sobredosis
```

### Violence and Threats (Spanish)
```
matar,mata a alguien,asesinato,ataque,terrorismo,bomba,explosivo,arma,disparar,apuñalar
violencia,pelea,lastimar,dañar,amenaza,amenazar,intimidar,agresión,golpiza
```

## Configuration Format

### Basic Format (Severity: Warning)
```
depression,anxiety,stress,sadness
```

### Advanced Format with Severity Levels
```
suicide:emergency,self-harm:critical,violence:critical,threat:warning,depression:warning
```

### Multi-language Combined
```
# English
suicide:emergency,self-harm:critical,kill myself:emergency

# German
selbstmord:emergency,selbstmordgedanken:emergency,mich umbringen:emergency

# French
suicide:emergency,pensées suicidaires:emergency,me tuer:emergency
```

## Implementation Guidelines

### Keyword Selection Principles
1. **Specificity**: Choose words that clearly indicate danger, not general emotional states
2. **Context Awareness**: Consider that some words might appear in safe contexts (e.g., "kill" in "kill time")
3. **Cultural Sensitivity**: Include culturally appropriate terms and phrases
4. **Regular Updates**: Review and update keyword lists based on emerging patterns

### False Positive Prevention
- Avoid single common words that could trigger inappropriately
- Consider phrase detection for more accuracy
- Implement confidence scoring for ambiguous detections
- Allow administrators to review and override false positives

### Multi-language Support
- Maintain separate keyword lists for each supported language
- Consider transliteration for languages with different scripts
- Include common foreign words used in local contexts
- Support both native language keywords and English loanwords

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

### Negative Test Cases (Should Not Trigger)
- "I want to kill time waiting"
- "The movie was harmful to my sleep schedule"
- "I'm planning to surprise my friend"
- "I feel like going for a run"

### Edge Cases
- Typos and variations ("suicde", "selfharm")
- Abbreviations ("SI" for self-injury)
- Emojis and special characters
- Code switching between languages

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





