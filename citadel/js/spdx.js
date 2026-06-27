/* CITADEL — SPDX 2.3 SBOM generator + PURL/CPE helpers
 * Produces a deterministic SPDX 2.3 JSON document from the existing SBOM
 * components plus integrity hashes. Mirrors js/sbom.js ecosystem handling so
 * SPDX output is consistent with the CycloneDX output.
 * Pure: no network, no DOM, no Date (timestamps are passed via opts).
 * Worker-safe: window.CITADEL.spdx
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  // Ecosystem -> purl type (same set as js/sbom.js).
  const PURL_TYPES = {
    npm: 'npm', pypi: 'pypi', maven: 'maven', golang: 'golang',
    gem: 'gem', composer: 'composer', cargo: 'cargo', nuget: 'nuget'
  };

  // Integrity alg -> SPDX checksum algorithm (skip 'h1'/unknown).
  const CHECKSUM_ALGS = { sha512: 'SHA512', sha256: 'SHA256', sha1: 'SHA1' };

  function str(v) {
    return (v === null || v === undefined) ? '' : String(v);
  }

  // Encode a purl name path, keeping the @scope/name and / separators intact
  // (per purl spec a leading @ in npm names is kept; '/' separates namespace).
  function encName(name) {
    return name.split('/').map(encodeURIComponent).join('/');
  }

  // pkg:<type>/<name>@<version>  — name URL-encoded, scope preserved.
  function purl(component) {
    const c = component || {};
    const t = PURL_TYPES[c.ecosystem] || 'generic';
    const name = str(c.name);
    const version = str(c.version) || '*';
    return 'pkg:' + t + '/' + encName(name) + '@' + encodeURIComponent(version);
  }

  // Escape a CPE 2.3 attribute: lowercase, escape colons/spaces/special chars
  // with a backslash per CPE 2.3 formatted-string binding.
  function cpeAttr(v) {
    return str(v).toLowerCase().replace(/([\\:?*!"#$%&'()+,/;<=>@[\]^`{|}~ ])/g, '\\$1');
  }

  // best-effort cpe:2.3:a:<vendor>:<product>:<version>:*:*:*:*:*:*:*
  function cpe(component) {
    const c = component || {};
    const raw = str(c.name);
    if (!raw.trim()) return 'NOASSERTION';
    // Strip npm scope (@scope/name -> name) for the product/vendor pieces.
    let bare = raw;
    if (bare.charAt(0) === '@' && bare.indexOf('/') !== -1) {
      bare = bare.slice(bare.indexOf('/') + 1);
    }
    // maven group:artifact -> use the last segment as product, group as vendor.
    let vendor = bare;
    let product = bare;
    if (bare.indexOf(':') !== -1) {
      const parts = bare.split(':');
      vendor = parts[0];
      product = parts[parts.length - 1];
    } else if (bare.indexOf('/') !== -1) {
      const parts = bare.split('/');
      product = parts[parts.length - 1];
      vendor = parts[0];
    }
    const version = str(c.version) || '*';
    return 'cpe:2.3:a:' + cpeAttr(vendor) + ':' + cpeAttr(product) + ':' +
      cpeAttr(version) + ':*:*:*:*:*:*:*';
  }

  // Stable namespace from opts.name (no randomness, no Date).
  function stableNamespace(name) {
    const slug = str(name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'citadel-sbom';
    return 'urn:citadel:spdx:' + slug;
  }

  // hashes key = ecosystem + '|' + name.toLowerCase() + '|' + version
  function hashKey(c) {
    return str(c.ecosystem) + '|' + str(c.name).toLowerCase() + '|' + str(c.version);
  }

  function checksumsFor(component, hashes) {
    if (!hashes) return [];
    const entry = hashes[hashKey(component)];
    if (!entry || !entry.alg) return [];
    const alg = CHECKSUM_ALGS[String(entry.alg).toLowerCase()];
    if (!alg || !entry.value) return [];
    return [{ algorithm: alg, checksumValue: str(entry.value) }];
  }

  function packageFor(component, index, hashes) {
    const c = component || {};
    const id = 'SPDXRef-Package-' + index;
    const license = str(c.license) || 'NOASSERTION';
    const pkg = {
      SPDXID: id,
      name: str(c.name),
      versionInfo: str(c.version) || 'NOASSERTION',
      downloadLocation: 'NOASSERTION',
      filesAnalyzed: false,
      licenseConcluded: license,
      licenseDeclared: license,
      copyrightText: 'NOASSERTION',
      externalRefs: [
        { referenceCategory: 'PACKAGE-MANAGER', referenceType: 'purl', referenceLocator: purl(c) },
        { referenceCategory: 'SECURITY', referenceType: 'cpe23Type', referenceLocator: cpe(c) }
      ]
    };
    const checksums = checksumsFor(c, hashes);
    if (checksums.length) pkg.checksums = checksums;
    return pkg;
  }

  // Build an SPDX 2.3 JSON document object.
  function document(components, hashes, opts) {
    const list = Array.isArray(components) ? components : [];
    const o = opts || {};
    const name = str(o.name) || 'CITADEL SBOM';
    const namespace = str(o.namespace) || stableNamespace(name);
    const created = str(o.timestamp);

    const packages = [];
    const relationships = [];
    for (let i = 0; i < list.length; i++) {
      const pkg = packageFor(list[i], i, hashes);
      packages.push(pkg);
      relationships.push({
        spdxElementId: 'SPDXRef-DOCUMENT',
        relationshipType: 'DESCRIBES',
        relatedSpdxElement: pkg.SPDXID
      });
    }

    return {
      spdxVersion: 'SPDX-2.3',
      dataLicense: 'CC0-1.0',
      SPDXID: 'SPDXRef-DOCUMENT',
      name: name,
      documentNamespace: namespace,
      creationInfo: {
        created: created,
        creators: ['Tool: CITADEL']
      },
      packages: packages,
      relationships: relationships
    };
  }

  CITADEL.spdx = { document: document, purl: purl, cpe: cpe };
})(window);
