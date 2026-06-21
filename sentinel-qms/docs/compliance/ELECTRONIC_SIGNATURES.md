# Electronic Signatures

How Sentinel QMS implements 21 CFR Part 11-style electronic signatures: what is
captured, how signatures are bound to records, when re-authentication is
required, and which actions are signature-bearing.

> Authoritative implementation: `backend/app/services/signatures.py`,
> `backend/app/models/user.py` (`ElectronicSignature`),
> `backend/app/schemas/common.py` (`ESignatureIn`). See also
> [21-cfr-part-11.md](21-cfr-part-11.md).

---

## 1. What a signature captures

Each signature is an immutable `ElectronicSignature` row with:

| Field | Meaning |
|-------|---------|
| `signer_id` | The authenticated user who signed (FK to `users`). |
| `signer_name` | The signer's full name captured at signing time. |
| `meaning` | The signed meaning — e.g. `approved`, `reviewed`, `dispositioned` (≤128 chars). |
| `reason` | Optional free-text justification (≤2000 chars). |
| `entity_type` / `entity_id` | The exact record signed (type + id). |
| `signed_hash` | SHA-256 binding hash (see §3). |
| `signed_at` | UTC timestamp, set server-side at signing. |

The signature is **append-only**: signatures are never edited or deleted, and
they are written in the same transaction as the action they authorize, so the
record change and its signature share atomicity.

## 2. Re-authentication (two-component signing)

By default (`require_reauth=True`) the signer must re-enter their password at the
moment of signing. The platform re-verifies it against the stored bcrypt hash
(`verify_password`) and rejects the signature with `401` if it is missing or
wrong. This satisfies 21 CFR Part 11 §11.200(a)(1): a signature requires **two
distinct components** — the established authenticated session **plus** the
password supplied at signing.

The password is **only verified** — it is never stored in, or recoverable from,
the signature record.

## 3. Record binding (tamper-evidence)

The `signed_hash` is computed as:

```
SHA-256( "{signer_id}|{signer_email}|{entity_type}|{entity_id}|{meaning}|{signed_at ISO-8601}" )
```

Because the hash incorporates the signer, the specific record, the meaning, and
the timestamp, a signature **cannot be transplanted** to a different record,
signer, or meaning without invalidating the hash. This implements §11.70
(signature/record linking — signatures cannot be excised, copied, or transferred)
and §11.50 (signed manifestations carry the signer, date/time, and meaning).

## 4. Signature-bearing actions

Signatures are required on the regulated state transitions that gate product and
quality decisions, including:

| Module | Action | Typical meaning |
|--------|--------|-----------------|
| Nonconformance (NCR/MRB) | Disposition (use-as-is, rework, scrap, repair, RTV) | `dispositioned` |
| CAPA (8D) | Verification of effectiveness & closure | `approved` / `closed` |
| Document control | Revision approval / release | `approved` |
| Engineering change (ECN/ECO) | Change approval | `approved` |

Each signing endpoint accepts the `ESignatureIn` payload (`meaning`, optional
`reason`, and `password` for re-auth) and records the signature alongside the
audit-log entry for the action.

## 5. Audit-trail relationship

Electronic signatures complement — they do not replace — the immutable
[audit log](../architecture/security-architecture.md). Every signature-bearing
action writes **both** a signature (the deliberate, attributable act of signing)
and an audit-log entry (the system record of the change), giving assessors a
complete, cross-referenced trail.

## 6. Conformance summary (21 CFR Part 11)

| Requirement | How it is met |
|-------------|---------------|
| §11.50 Signature manifestations | Signer name, UTC date/time, and meaning stored and displayable. |
| §11.70 Signature/record linking | SHA-256 hash binds signer + record + meaning + time; tamper-evident. |
| §11.100(a) Uniqueness | A signature is tied to one authenticated individual; never reused or reassigned. |
| §11.200(a)(1) Two components | Authenticated session **plus** password re-auth at signing. |

For the full clause-by-clause analysis, see [21-cfr-part-11.md](21-cfr-part-11.md).
