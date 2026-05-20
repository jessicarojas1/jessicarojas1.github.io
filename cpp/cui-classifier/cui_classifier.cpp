/*
 * cui_classifier.cpp — CUI / Controlled Unclassified Information Pattern Classifier
 *
 * Description:
 *   Recursively scans text files in a directory tree for patterns indicative of
 *   Controlled Unclassified Information (CUI) as defined by NARA 32 CFR Part 2002.
 *   Covers ITAR (22 CFR 120-130), EAR (15 CFR 730-774), PII/Privacy Act data,
 *   DoD marking conventions, HIPAA PHI identifiers, ICS/SCADA keywords, CVE
 *   references, and financial PAN data.
 *
 * Build:
 *   g++ -std=c++17 -O2 cui_classifier.cpp -o cui-classifier
 *
 * Usage:
 *   ./cui-classifier <directory> [--ext <.txt,.md,...>] [--json] [--quiet]
 *
 *   ./cui-classifier /home/user/docs/
 *   ./cui-classifier /opt/repo --ext .txt,.py,.yaml
 *   ./cui-classifier /var/mail --json > results.json
 *   ./cui-classifier /tmp/uploads --quiet  # exit-code only, no output
 *
 * Exit codes:
 *   0  — no CUI patterns found
 *   1  — usage / I/O error
 *   2  — CUI patterns found
 *
 * Deployment notes:
 *   Runs entirely on the native filesystem. Not suitable for WebAssembly.
 *   Binary files are skipped automatically (NULL-byte detection).
 *   For large repositories, consider --ext to narrow the file set.
 *   Never log matched content to a shared syslog facility — output may itself
 *   contain CUI and should be treated as sensitive.
 */

#include <algorithm>
#include <filesystem>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <regex>
#include <unistd.h>
#include <set>
#include <sstream>
#include <string>
#include <unordered_set>
#include <vector>

namespace fs = std::filesystem;

// ─────────────────────────── pattern definitions ────────────────────────────

struct Pattern {
    std::string  category;      // top-level CUI category
    std::string  subcategory;   // specific type
    std::regex   re;
    bool         redact;        // redact matched group in output
};

// Luhn algorithm for credit card validation
static bool luhn_check(const std::string& digits) {
    if (digits.size() < 13 || digits.size() > 19) return false;
    int sum = 0;
    bool alt = false;
    for (int i = static_cast<int>(digits.size()) - 1; i >= 0; --i) {
        if (!std::isdigit(static_cast<unsigned char>(digits[i]))) return false;
        int d = digits[i] - '0';
        if (alt) { d *= 2; if (d > 9) d -= 9; }
        sum += d;
        alt = !alt;
    }
    return (sum % 10) == 0;
}

static std::vector<Pattern> build_patterns() {
    using S = std::string;
    auto f = std::regex::ECMAScript | std::regex::icase;

    // clang-format off
    return {
        // ── ITAR ──────────────────────────────────────────────────────────
        { "ITAR", "USML Reference",
          std::regex(R"(\bUSML\s+(?:Category|Cat\.?)\s+[IVX]{1,6}[A-Z]?\b)", f), false },
        { "ITAR", "ITAR Marking",
          std::regex(R"(\bITAR[-\s]?controlled\b|\bDDTC\b|\bMunitions\s+List\b)", f), false },
        { "ITAR", "Technical Data Export",
          std::regex(R"(\bExport\s+Controlled\b.*?\b(?:22\s*CFR|ITAR)\b)", f), false },

        // ── EAR ───────────────────────────────────────────────────────────
        { "EAR", "ECCN Reference",
          std::regex(R"(\bECCN\s+[0-9][A-E][0-9]{2,3}(?:\.[a-z])?\b)", f), false },
        { "EAR", "EAR/CFR Citation",
          std::regex(R"(\b15\s+C\.?F\.?R\.?\s+(?:Part\s+)?(?:730|734|736|738|740|742|744|746|748|750|752|754|756|758|760|762|764|766|768|770|772|774)\b)", f), false },

        // ── DoD / Government Markings ──────────────────────────────────────
        { "DoD", "CUI Marking",
          std::regex(R"(\bCUI\b|\bFOUO\b|\bFor\s+Official\s+Use\s+Only\b)", f), false },
        { "DoD", "NOFORN / Releasability",
          std::regex(R"(\bNOFORN\b|\bREL\s+TO\s+[A-Z,\s]{2,30}\b|\bORCON\b|\bPROPIN\b)", f), false },
        { "DoD", "Classification Remnant",
          std::regex(R"(\b(?:SECRET|TOP\s+SECRET|CONFIDENTIAL|TS/SCI)\b)", f), false },

        // ── Privacy / PII ─────────────────────────────────────────────────
        { "Privacy", "US Social Security Number",
          std::regex(R"(\b(?!000|666|9\d\d)\d{3}-(?!00)\d{2}-(?!0000)\d{4}\b)", f), true },
        { "Privacy", "Passport Number",
          std::regex(R"(\b[A-Z]{1,2}[0-9]{6,9}\b)", f), true },
        { "Privacy", "Date of Birth Pattern",
          std::regex(R"(\bD\.?O\.?B\.?\s*:?\s*\d{1,2}[/\-\.]\d{1,2}[/\-\.]\d{2,4}\b)", f), true },

        // ── HIPAA PHI ──────────────────────────────────────────────────────
        { "HIPAA", "Medical Record Indicator",
          std::regex(R"(\b(?:MRN|Medical\s+Record\s+(?:Number|No\.?)|Patient\s+ID)\s*[:#]?\s*\d{4,12}\b)", f), true },
        { "HIPAA", "Diagnosis / ICD Code",
          std::regex(R"(\b(?:ICD-?(?:9|10)(?:-CM)?|diagnosis\s+code)\s*[:#]?\s*[A-Z]\d{2,5}(?:\.\d{1,4})?\b)", f), false },
        { "HIPAA", "Protected Health Term",
          std::regex(R"(\b(?:HIV|AIDS|substance\s+abuse|mental\s+health\s+record|psychiatric\s+(?:diagnosis|record))\b)", f), false },

        // ── CTI / CVE ─────────────────────────────────────────────────────
        { "CTI", "CVE Identifier",
          std::regex(R"(\bCVE-\d{4}-\d{4,7}\b)", f), false },
        { "CTI", "CVSS Score",
          std::regex(R"(\bCVSS\s*(?:v[23]\s*)?(?:Score\s*)?(?:Base\s*)?[:\-]?\s*(?:10(?:\.0)?|[0-9]\.[0-9])\b)", f), false },

        // ── ICS / SCADA ───────────────────────────────────────────────────
        { "ICS", "SCADA / PLC Reference",
          std::regex(R"(\b(?:SCADA|DCS|HMI|PLC|RTU|IED)\b)", f), false },
        { "ICS", "Industrial Protocol",
          std::regex(R"(\b(?:DNP3|Modbus(?:\s*TCP)?|PROFINET|EtherNet/IP|IEC\s*61850|BACnet|OPC-UA)\b)", f), false },
        { "ICS", "Safety System Reference",
          std::regex(R"(\b(?:SIS|SIL[-\s]?[1-4]|Safety\s+Instrumented\s+System|Emergency\s+Shutdown|ESD\s+System)\b)", f), false },

        // ── Finance ───────────────────────────────────────────────────────
        // Note: Luhn validation applied in code; regex captures candidate PANs
        { "Finance", "Payment Card Number",
          std::regex(R"(\b(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14}|3[47][0-9]{13}|6(?:011|5[0-9]{2})[0-9]{12}|(?:2131|1800|35\d{3})\d{11})\b)", f), true },
        { "Finance", "ABA Routing Number",
          std::regex(R"(\b(?:0[0-9]{8}|1[0-2][0-9]{7}|2[0-9]{8}|3[0-2][0-9]{7})\b)", f), true },
    };
    // clang-format on
}

// ─────────────────────────── types ──────────────────────────────────────────

struct Hit {
    std::string filepath;
    int         line_number;
    std::string category;
    std::string subcategory;
    std::string context;   // up to 120 chars surrounding the match
};

struct ScanStats {
    int files_scanned = 0;
    int files_flagged = 0;
    int total_hits    = 0;
};

// ─────────────────────────── helpers ────────────────────────────────────────

// Returns true if the file appears to be binary (contains null bytes in first 8 KB)
static bool is_binary(const fs::path& p) {
    std::ifstream in(p, std::ios::binary);
    if (!in) return false;
    char buf[8192];
    in.read(buf, sizeof(buf));
    auto n = in.gcount();
    return std::any_of(buf, buf + n, [](char c) { return c == '\0'; });
}

// Truncate a string to max_len, appending "…" if clipped
static std::string truncate(const std::string& s, std::size_t max_len) {
    if (s.size() <= max_len) return s;
    return s.substr(0, max_len - 1) + "\xe2\x80\xa6"; // UTF-8 ellipsis
}

// Redact matched text with asterisks (keep first and last char)
static std::string redact(const std::string& matched) {
    if (matched.size() <= 2) return std::string(matched.size(), '*');
    return matched.front() + std::string(matched.size() - 2, '*') + matched.back();
}

static std::string escape_json(const std::string& s) {
    std::string out;
    for (char c : s) {
        if (c == '"')  out += "\\\"";
        else if (c == '\\') out += "\\\\";
        else if (c == '\n') out += "\\n";
        else if (c == '\r') out += "\\r";
        else if (c == '\t') out += "\\t";
        else out += c;
    }
    return out;
}

// ─────────────────────────── scanning ───────────────────────────────────────

static std::vector<Hit> scan_file(
        const fs::path& path,
        const std::vector<Pattern>& patterns) {

    std::vector<Hit> hits;
    std::ifstream in(path);
    if (!in) return hits;

    std::string line;
    int lineno = 0;

    while (std::getline(in, line)) {
        ++lineno;
        for (auto& pat : patterns) {
            std::sregex_iterator it(line.begin(), line.end(), pat.re);
            std::sregex_iterator end;
            while (it != end) {
                std::smatch m = *it;
                std::string matched_text = m[0].str();

                // Luhn validation for credit card numbers
                if (pat.subcategory == "Payment Card Number") {
                    std::string digits;
                    for (char c : matched_text)
                        if (std::isdigit(static_cast<unsigned char>(c)))
                            digits += c;
                    if (!luhn_check(digits)) { ++it; continue; }
                }

                // Build context: up to 60 chars before and after
                std::size_t mstart = static_cast<std::size_t>(m.position(0));
                std::size_t ctx_start = (mstart > 60) ? mstart - 60 : 0;
                std::string ctx = line.substr(ctx_start, 120 + matched_text.size());

                if (pat.redact) {
                    // Replace the match in context with redacted version
                    auto pos = ctx.find(matched_text);
                    if (pos != std::string::npos)
                        ctx.replace(pos, matched_text.size(), redact(matched_text));
                }

                hits.push_back({
                    path.string(),
                    lineno,
                    pat.category,
                    pat.subcategory,
                    truncate(ctx, 100)
                });
                ++it;
            }
        }
    }
    return hits;
}

// ─────────────────────────── output ─────────────────────────────────────────

static void print_table(const std::vector<Hit>& hits, bool use_colour) {
    const char* RED    = use_colour ? "\033[1;31m" : "";
    const char* YELLOW = use_colour ? "\033[1;33m" : "";
    const char* CYAN   = use_colour ? "\033[0;36m" : "";
    const char* RESET  = use_colour ? "\033[0m"    : "";

    for (auto& h : hits) {
        auto col = (h.category == "ITAR" || h.category == "DoD") ? RED
                 : (h.category == "Privacy" || h.category == "HIPAA") ? YELLOW
                 : CYAN;

        std::cout << col
                  << "[" << std::setw(7) << std::left << h.category << "] "
                  << RESET
                  << std::setw(30) << std::left << h.subcategory << "  "
                  << h.filepath << ":" << h.line_number << "\n"
                  << "    > " << h.context << "\n";
    }
}

static void print_json(const std::vector<Hit>& hits) {
    std::cout << "[\n";
    for (std::size_t i = 0; i < hits.size(); ++i) {
        auto& h = hits[i];
        std::cout << "  {\n"
                  << "    \"file\": \""        << escape_json(h.filepath)    << "\",\n"
                  << "    \"line\": "           << h.line_number              << ",\n"
                  << "    \"category\": \""     << escape_json(h.category)    << "\",\n"
                  << "    \"subcategory\": \""  << escape_json(h.subcategory) << "\",\n"
                  << "    \"context\": \""      << escape_json(h.context)     << "\"\n"
                  << "  }" << (i + 1 < hits.size() ? "," : "") << "\n";
    }
    std::cout << "]\n";
}

// ─────────────────────────── main ───────────────────────────────────────────

static void usage(const char* prog) {
    std::cerr << "Usage: " << prog
              << " <directory> [--ext <.txt,.py,...>] [--json] [--quiet]\n"
              << "  --ext  <list>  comma-separated file extensions to scan (default: all text)\n"
              << "  --json         JSON output\n"
              << "  --quiet        suppress per-hit output; use exit code only\n";
}

int main(int argc, char* argv[]) {
    if (argc < 2) { usage(argv[0]); return 1; }

    std::string dir_arg;
    std::unordered_set<std::string> allowed_exts;
    bool json_mode  = false;
    bool quiet_mode = false;

    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        if (a == "--json")  { json_mode  = true; }
        else if (a == "--quiet") { quiet_mode = true; }
        else if (a == "--ext") {
            if (i + 1 >= argc) { usage(argv[0]); return 1; }
            std::string exts = argv[++i];
            std::stringstream ss(exts);
            std::string tok;
            while (std::getline(ss, tok, ','))
                if (!tok.empty()) allowed_exts.insert(tok);
        } else if (dir_arg.empty()) {
            dir_arg = a;
        }
    }

    if (dir_arg.empty()) { usage(argv[0]); return 1; }

    fs::path root(dir_arg);
    if (!fs::is_directory(root)) {
        std::cerr << "error: not a directory: " << dir_arg << "\n";
        return 1;
    }

    auto patterns = build_patterns();
    std::vector<Hit> all_hits;
    ScanStats stats;
    std::set<std::string> flagged_files;

    for (auto& entry : fs::recursive_directory_iterator(
             root, fs::directory_options::skip_permission_denied)) {
        if (!fs::is_regular_file(entry.path())) continue;

        std::string ext = entry.path().extension().string();
        if (!allowed_exts.empty() && allowed_exts.find(ext) == allowed_exts.end())
            continue;

        if (is_binary(entry.path())) continue;

        ++stats.files_scanned;
        auto hits = scan_file(entry.path(), patterns);
        if (!hits.empty()) {
            flagged_files.insert(entry.path().string());
            stats.total_hits += static_cast<int>(hits.size());
            for (auto& h : hits) all_hits.push_back(std::move(h));
        }
    }

    stats.files_flagged = static_cast<int>(flagged_files.size());

    if (!quiet_mode) {
        bool use_colour = !json_mode && isatty(fileno(stdout));
        if (json_mode)
            print_json(all_hits);
        else
            print_table(all_hits, use_colour);

        std::cerr << "\n── Summary ──────────────────────────────\n"
                  << "  Files scanned : " << stats.files_scanned << "\n"
                  << "  Files flagged : " << stats.files_flagged << "\n"
                  << "  Total hits    : " << stats.total_hits    << "\n"
                  << "─────────────────────────────────────────\n";
    }

    return (stats.total_hits > 0) ? 2 : 0;
}
