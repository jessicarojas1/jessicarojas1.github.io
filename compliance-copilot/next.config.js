/** @type {import('next').NextConfig} */
const nextConfig = {
  // Emit a self-contained production server (.next/standalone) so the Docker
  // image can run `node server.js` with only the traced dependencies — smaller,
  // faster to boot, and non-root friendly. `next start` still works for hosts
  // that do not use the container image (e.g. Vercel, Render Node service).
  output: 'standalone',
  images: {
    remotePatterns: [
      { protocol: 'https', hostname: '*.supabase.co' }
    ]
  }
};

module.exports = nextConfig;
