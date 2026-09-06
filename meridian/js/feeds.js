/* MERIDIAN — Curated open-source portals.
 * A passive, lawful launch directory only. MERIDIAN does NOT fetch, scrape,
 * scan, or authenticate against any of these; it links out for situational
 * awareness. Grouped by intelligence domain.
 * window.MERIDIAN.feeds
 */
(function (root) {
  'use strict';
  const M = root.MERIDIAN = root.MERIDIAN || {};

  const PORTALS = [
    { group: 'U.S. Government & Defense', links: [
      { name: 'DoD News', url: 'https://www.defense.gov/News/' },
      { name: 'DoD Contracts (daily)', url: 'https://www.defense.gov/News/Contracts/' },
      { name: 'DARPA News', url: 'https://www.darpa.mil/news' },
      { name: 'Congress.gov (bills)', url: 'https://www.congress.gov/' },
      { name: 'GAO Reports', url: 'https://www.gao.gov/reports-testimonies' },
      { name: 'White House / OSTP', url: 'https://www.whitehouse.gov/ostp/' }
    ]},
    { group: 'Contracting & Acquisition', links: [
      { name: 'SAM.gov (opportunities)', url: 'https://sam.gov/search/?index=opp' },
      { name: 'USAspending.gov', url: 'https://www.usaspending.gov/' },
      { name: 'GSA / Acquisition.gov', url: 'https://www.acquisition.gov/' },
      { name: 'DCSA (industrial security)', url: 'https://www.dcsa.mil/' },
      { name: 'DIU', url: 'https://www.diu.mil/latest' }
    ]},
    { group: 'Cyber Threat Intelligence', links: [
      { name: 'CISA Advisories', url: 'https://www.cisa.gov/news-events/cybersecurity-advisories' },
      { name: 'CISA KEV Catalog', url: 'https://www.cisa.gov/known-exploited-vulnerabilities-catalog' },
      { name: 'NVD (NIST CVE)', url: 'https://nvd.nist.gov/vuln/search' },
      { name: 'MITRE CVE', url: 'https://www.cve.org/' },
      { name: 'NSA Cybersecurity Advisories', url: 'https://www.nsa.gov/Press-Room/Cybersecurity-Advisories-Guidance/' },
      { name: 'US-CERT / ICS-CERT', url: 'https://www.cisa.gov/news-events/cybersecurity-advisories?f%5B0%5D=advisory_type%3A95' }
    ]},
    { group: 'Aerospace, Defense & Space Press', links: [
      { name: 'Defense News', url: 'https://www.defensenews.com/' },
      { name: 'Breaking Defense', url: 'https://breakingdefense.com/' },
      { name: 'Aviation Week', url: 'https://aviationweek.com/' },
      { name: 'SpaceNews', url: 'https://spacenews.com/' },
      { name: 'The War Zone', url: 'https://www.twz.com/' },
      { name: 'Janes', url: 'https://www.janes.com/' }
    ]},
    { group: 'Space & Strategic Systems', links: [
      { name: 'U.S. Space Force News', url: 'https://www.spaceforce.mil/News/' },
      { name: 'NASA News', url: 'https://www.nasa.gov/news/' },
      { name: 'NORAD/NORTHCOM', url: 'https://www.northcom.mil/Newsroom/' },
      { name: 'ESA', url: 'https://www.esa.int/Newsroom' }
    ]},
    { group: 'Allies, Partners & International', links: [
      { name: 'NATO News', url: 'https://www.nato.int/cps/en/natohq/news.htm' },
      { name: 'UK MoD', url: 'https://www.gov.uk/government/organisations/ministry-of-defence' },
      { name: 'EU EEAS', url: 'https://www.eeas.europa.eu/eeas/press-material_en' },
      { name: 'Japan MoD', url: 'https://www.mod.go.jp/en/' },
      { name: 'Australia DoD', url: 'https://www.defence.gov.au/news-events' }
    ]},
    { group: 'Think Tanks & Analysis', links: [
      { name: 'CSIS', url: 'https://www.csis.org/analysis' },
      { name: 'RAND', url: 'https://www.rand.org/pubs.html' },
      { name: 'ISW (war studies)', url: 'https://www.understandingwar.org/' },
      { name: 'Atlantic Council', url: 'https://www.atlanticcouncil.org/' },
      { name: 'CSET (Georgetown, AI)', url: 'https://cset.georgetown.edu/publications/' }
    ]},
    { group: 'AI, Regulatory & Industry', links: [
      { name: 'NIST AI / AISI', url: 'https://www.nist.gov/artificial-intelligence' },
      { name: 'Federal Register', url: 'https://www.federalregister.gov/' },
      { name: 'SEC EDGAR (filings)', url: 'https://www.sec.gov/cgi-bin/browse-edgar?action=getcompany' },
      { name: 'BIS (export controls)', url: 'https://www.bis.doc.gov/index.php/all-articles' },
      { name: 'DDTC (ITAR)', url: 'https://www.pmddtc.state.gov/' }
    ]}
  ];

  M.feeds = { PORTALS };
})(window);
