/* CITADEL — PII & sensitive-data detection pack.
 * A new analysis dimension: exposed personal / regulated data (SSNs, payment
 * cards, IBANs, health identifiers, tokens, ...). Findings use the 'privacy'
 * category, which the compliance engine cross-walks to GDPR, HIPAA, PCI DSS,
 * SOC 2, ISO 27001 and NIST data-protection controls. Same rule shape as
 * rules.js; appended to CITADEL.rules and run by the standard scanner.
 * window.CITADEL.rules (extended)
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const EXTRA = [
    { id: 'pii-ssn', name: 'US Social Security Number', category: 'privacy',
      severity: 'high', cwe: 'CWE-359', langs: '*', confidence: 'high',
      re: /\b(?!000|666|9\d\d)\d{3}[-\s](?!00)\d{2}[-\s](?!0000)\d{4}\b/,
      remediation: 'Never store/transmit SSNs in code or logs; tokenize or encrypt at rest (PCI/HIPAA/GDPR).' },
    { id: 'pii-cc-visa', name: 'Payment card number (Visa)', category: 'privacy',
      severity: 'high', cwe: 'CWE-311', langs: '*', confidence: 'medium',
      re: /\b4\d{3}(?:[\s-]?\d{4}){3}\b/,
      remediation: 'Do not store full PANs (PCI DSS 3.x). Tokenize; mask to last 4 if display is needed.' },
    { id: 'pii-cc-mc', name: 'Payment card number (Mastercard)', category: 'privacy',
      severity: 'high', cwe: 'CWE-311', langs: '*', confidence: 'medium',
      re: /\b5[1-5]\d{2}(?:[\s-]?\d{4}){3}\b/,
      remediation: 'Do not store full PANs (PCI DSS 3.x). Tokenize; mask to last 4 if display is needed.' },
    { id: 'pii-cc-amex', name: 'Payment card number (Amex)', category: 'privacy',
      severity: 'high', cwe: 'CWE-311', langs: '*', confidence: 'medium',
      re: /\b3[47]\d{2}[\s-]?\d{6}[\s-]?\d{5}\b/,
      remediation: 'Do not store full PANs (PCI DSS 3.x). Tokenize; mask to last 4 if display is needed.' },
    { id: 'pii-iban', name: 'IBAN (bank account)', category: 'privacy',
      severity: 'medium', cwe: 'CWE-359', langs: '*', confidence: 'low',
      re: /\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){3,7}(?:[ ]?[A-Z0-9]{1,3})?\b/,
      remediation: 'Treat bank identifiers as sensitive personal data; encrypt and minimize storage (GDPR Art. 32).' },
    { id: 'pii-phi', name: 'Possible health (PHI) data', category: 'privacy',
      severity: 'medium', cwe: 'CWE-359', langs: '*', confidence: 'low',
      re: /\b(patient[_-]?id|medical[_-]?record(?:[_-]?number)?|\bmrn\b|icd-?10|health[_-]?record|diagnosis[_-]?code)\b/i,
      remediation: 'Protected Health Information must be encrypted and access-controlled (HIPAA 164.312).' },
    { id: 'pii-dob', name: 'Date-of-birth field', category: 'privacy',
      severity: 'low', cwe: 'CWE-359', langs: '*', confidence: 'low',
      re: /\b(date[_\s-]?of[_\s-]?birth|dob|birth[_\s-]?date)\b/i,
      remediation: 'Date of birth is regulated personal data; minimize collection and protect it.' },
    { id: 'pii-passport', name: 'Passport / national-ID field', category: 'privacy',
      severity: 'low', cwe: 'CWE-359', langs: '*', confidence: 'low',
      re: /\b(passport[_\s-]?(?:no|num|number|#)?|national[_\s-]?id|driver'?s?[_\s-]?licen[sc]e)\b/i,
      remediation: 'Government identifiers are sensitive PII; encrypt and restrict access.' },
    { id: 'pii-email', name: 'Email address in source', category: 'privacy',
      severity: 'info', cwe: 'CWE-359', langs: '*', confidence: 'low',
      re: /\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/,
      remediation: 'Avoid hardcoding personal email addresses; reference contacts via config, not source.' },
    { id: 'pii-phone', name: 'Phone number in source', category: 'privacy',
      severity: 'info', cwe: 'CWE-359', langs: '*', confidence: 'low',
      re: /\b(?:\+?1[\s.-]?)?\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}\b/,
      remediation: 'Avoid hardcoding personal phone numbers in source code.' },
    { id: 'pii-jwt', name: 'Hardcoded JWT token', category: 'secrets',
      severity: 'high', cwe: 'CWE-522', langs: '*', confidence: 'medium',
      re: /\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{8,}\b/,
      remediation: 'A hardcoded JWT can leak identity/claims and act as a live credential — revoke and remove.' }
  ];
  (CITADEL.rules = CITADEL.rules || []).push.apply(CITADEL.rules, EXTRA);
})(window);
