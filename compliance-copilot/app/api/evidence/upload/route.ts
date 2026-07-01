import { NextRequest, NextResponse } from 'next/server';
import { createClient } from '@supabase/supabase-js';
import { getSession, sessionAuthConfigured } from '@/lib/session';
import { requestId, withRequestId } from '@/lib/logger';

// POST /api/evidence/upload — server-side evidence intake.
//
// Accepts a multipart/form-data upload, stores the file in the (PRIVATE)
// Supabase Storage bucket using the service-role key, and writes an `evidence`
// metadata row. Returns the row plus a short-lived signed URL so the client can
// preview/download without the bucket ever being public.
//
// Security:
//   - Auth: requires a valid server-side session cookie AND an x-requested-with
//     header (CSRF defense — a cross-site form cannot set a custom header). Fails
//     closed in production when unauthenticated; open in dev for local demo.
//   - Upload hardening: extension allowlist + MIME allowlist + size cap, and a
//     RANDOMIZED stored object name (never the client-supplied filename) to
//     prevent path traversal / overwrite / content-type confusion.
//   - Writes go through the service-role client (bypasses RLS); the bucket stays
//     private and is read only via signed URLs.

const MAX_FILE_BYTES = 25 * 1024 * 1024; // 25 MB
const SIGNED_URL_TTL_S = 60 * 60; // 1 hour

// extension → allowed MIME types. Both must match to accept the file.
const ALLOWED: Record<string, string[]> = {
  pdf: ['application/pdf'],
  png: ['image/png'],
  jpg: ['image/jpeg'],
  jpeg: ['image/jpeg'],
  gif: ['image/gif'],
  webp: ['image/webp'],
  txt: ['text/plain'],
  csv: ['text/csv', 'application/vnd.ms-excel', 'text/plain'],
  log: ['text/plain'],
  zip: ['application/zip', 'application/x-zip-compressed'],
};

const EVIDENCE_TYPES = new Set([
  'policy', 'procedure', 'screenshot', 'log', 'configuration', 'test_result', 'interview', 'other',
]);

function bucketName(): string {
  return process.env.NEXT_PUBLIC_EVIDENCE_BUCKET || 'evidence-files';
}

function serviceClient() {
  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!url || !key) return null;
  return createClient(url, key, { auth: { persistSession: false } });
}

function ext(name: string): string {
  const m = /\.([A-Za-z0-9]+)$/.exec(name || '');
  return m ? m[1].toLowerCase() : '';
}

function randomName(extension: string): string {
  let id: string;
  try {
    id = crypto.randomUUID();
  } catch {
    id = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
  }
  return extension ? `${id}.${extension}` : id;
}

export async function POST(req: NextRequest) {
  const rid = requestId(req.headers);
  const log = withRequestId(rid, { route: '/api/evidence/upload' });

  // --- Auth + CSRF ---------------------------------------------------------
  const hasReqHeader = Boolean(req.headers.get('x-requested-with'));
  const session = hasReqHeader ? getSession(req) : null;
  if (!session) {
    // Fail closed in production; allow in dev for local demo convenience.
    if (process.env.NODE_ENV === 'production') {
      log.warn('upload rejected: unauthenticated', { status: 401, configured: sessionAuthConfigured() });
      return NextResponse.json({ error: 'Unauthorized' }, { status: 401 });
    }
  }

  const supabase = serviceClient();
  if (!supabase) {
    log.error('upload rejected: storage not configured', { status: 503 });
    return NextResponse.json({ error: 'Storage is not configured on this server.' }, { status: 503 });
  }

  // --- Parse multipart form ------------------------------------------------
  let form: FormData;
  try {
    form = await req.formData();
  } catch {
    return NextResponse.json({ error: 'Expected multipart/form-data' }, { status: 400 });
  }

  const file = form.get('file');
  if (!(file instanceof File)) {
    return NextResponse.json({ error: 'Missing file' }, { status: 400 });
  }
  if (file.size === 0) {
    return NextResponse.json({ error: 'Empty file' }, { status: 400 });
  }
  if (file.size > MAX_FILE_BYTES) {
    return NextResponse.json(
      { error: `File too large (max ${Math.floor(MAX_FILE_BYTES / (1024 * 1024))} MB)` },
      { status: 413 },
    );
  }

  // --- Upload hardening: extension + MIME allowlist ------------------------
  const extension = ext(file.name);
  const allowedMimes = ALLOWED[extension];
  if (!allowedMimes || !allowedMimes.includes(file.type)) {
    log.warn('upload rejected: disallowed type', { ext: extension, mime: file.type, status: 415 });
    return NextResponse.json({ error: 'Unsupported file type' }, { status: 415 });
  }

  // Optional metadata (sanitized).
  const title = (form.get('title') as string | null)?.toString().slice(0, 300)
    || file.name.replace(/\.[^.]+$/, '');
  const description = (form.get('description') as string | null)?.toString().slice(0, 2000) || null;
  const rawType = (form.get('type') as string | null)?.toString() || 'other';
  const type = EVIDENCE_TYPES.has(rawType) ? rawType : 'other';
  const controlIds = form.getAll('control_ids').map((v) => v.toString()).filter(Boolean);
  const tags = (form.get('tags') as string | null)?.toString()
    .split(',').map((t) => t.trim()).filter(Boolean).slice(0, 25) || [];

  // Randomized stored object path — never trust the client filename.
  const objectPath = `${new Date().getUTCFullYear()}/${randomName(extension)}`;

  // --- Store the object (private bucket) -----------------------------------
  const bucket = bucketName();
  const bytes = new Uint8Array(await file.arrayBuffer());
  const { error: uploadError } = await supabase.storage
    .from(bucket)
    .upload(objectPath, bytes, { contentType: file.type, upsert: false });

  if (uploadError) {
    log.error('upload failed: storage write', { status: 502 });
    return NextResponse.json({ error: 'Failed to store file' }, { status: 502 });
  }

  // --- Write the evidence metadata row -------------------------------------
  const { data: row, error: insertError } = await supabase
    .from('evidence')
    .insert({
      control_ids: controlIds,
      title,
      description,
      type,
      file_url: `${bucket}/${objectPath}`, // storage ref, not a public URL
      file_name: file.name,
      file_size: file.size,
      tags,
      uploaded_by: session?.sub ?? 'local',
      reviewed: false,
    })
    .select()
    .single();

  if (insertError) {
    // Best-effort cleanup so we don't orphan the stored object.
    await supabase.storage.from(bucket).remove([objectPath]).catch(() => {});
    log.error('upload failed: metadata insert', { status: 502 });
    return NextResponse.json({ error: 'Failed to record evidence' }, { status: 502 });
  }

  // Short-lived signed URL for immediate preview (bucket stays private).
  const { data: signed } = await supabase.storage
    .from(bucket)
    .createSignedUrl(objectPath, SIGNED_URL_TTL_S);

  log.info('upload ok', { evidence_id: row?.id, bytes: file.size, type });
  return NextResponse.json(
    { ok: true, evidence: row, signedUrl: signed?.signedUrl ?? null, expires_in_s: SIGNED_URL_TTL_S },
    { status: 201, headers: { 'x-request-id': rid } },
  );
}

export const dynamic = 'force-dynamic';
