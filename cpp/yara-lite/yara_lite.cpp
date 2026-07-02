/*
 * yara_lite.cpp — Lightweight YARA-Inspired Malware Pattern Scanner
 *
 * Description:
 *   Parses a subset of the YARA rule format and scans binary/text files for
 *   matching patterns. Supports exact ASCII/UTF-8 string matches, raw hex byte
 *   sequences, and case-insensitive matching via the `nocase` modifier.
 *   Logical conditions support `and`, `or`, `not`, and parentheses.
 *
 * Rule format supported:
 *   rule NAME {
 *       strings:
 *           $s1 = "plaintext string"
 *           $s2 = "case insensitive" nocase
 *           $h1 = { DE AD BE EF 00 ?? }   // ?? = wildcard byte
 *       condition:
 *           $s1 or ($h1 and not $s2)
 *   }
 *
 * Build:
 *   g++ -std=c++17 -O2 yara_lite.cpp -o yara-lite
 *
 * Usage:
 *   ./yara-lite --rules <rules.yrl> <target_file_or_dir>
 *   ./yara-lite --builtin <target_file>
 *   ./yara-lite --rules malware.yrl /opt/suspect/ --recursive
 *
 * Exit codes:
 *   0  — no rules matched
 *   1  — usage / parse error
 *   2  — at least one rule matched
 *
 * Deployment notes:
 *   Operates on filesystem paths only. Not suitable for WebAssembly.
 *   The scanner mmaps files when possible for performance.
 *   Wildcard bytes (??) in hex patterns match any single byte.
 */

#include <algorithm>
#include <cassert>
#include <cctype>
#include <cstdint>
#include <filesystem>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <optional>
#include <sstream>
#include <string>
#include <unordered_map>
#include <variant>
#include <vector>

namespace fs = std::filesystem;

// ─────────────────────────── data model ─────────────────────────────────────

struct PatternEntry {
    std::string identifier;           // e.g. "$s1"
    std::vector<uint8_t> bytes;       // compiled byte sequence
    std::vector<bool>    wildcard;    // parallel: true = wildcard (skip compare)
    bool nocase = false;
};

struct Rule {
    std::string              name;
    std::vector<PatternEntry> patterns;
    std::string              condition_raw;  // raw condition text for evaluation
};

struct MatchRecord {
    std::string rule_name;
    std::string identifier;
    std::size_t offset;
    std::string pattern_preview; // hex representation of matched bytes
};

// ─────────────────────────── built-in rules ─────────────────────────────────

static const std::string BUILTIN_RULES = R"(
rule MZ_Executable {
    strings:
        $mz = { 4D 5A }
    condition:
        $mz
}

rule ELF_Binary {
    strings:
        $elf = { 7F 45 4C 46 }
    condition:
        $elf
}

rule PowerShell_DownloadCradle {
    strings:
        $dl1 = "DownloadString"
        $dl2 = "DownloadFile"
        $iex = "IEX"
        $wc  = "WebClient"
    condition:
        ($dl1 or $dl2) and $iex and $wc
}

rule Suspicious_C2_UserAgent {
    strings:
        $ua1 = "Mozilla/5.0 (compatible; MSIE 9.0"
        $ua2 = "python-requests"
        $ua3 = "curl/7.4"
        $ua4 = "Wget/1.1"
    condition:
        $ua1 or $ua2 or $ua3 or $ua4
}

rule Base64_Encoded_PE {
    strings:
        $b64_mz_1 = "TVqQ"
        $b64_mz_2 = "TVpQ"
        $b64_mz_3 = "TVoA"
        $b64_mz_4 = "TVpA"
    condition:
        $b64_mz_1 or $b64_mz_2 or $b64_mz_3 or $b64_mz_4
}
)";

// ─────────────────────────── rule parser ────────────────────────────────────

static std::string strip_comments(const std::string& src) {
    std::string out;
    out.reserve(src.size());
    for (std::size_t i = 0; i < src.size(); ++i) {
        if (i + 1 < src.size() && src[i] == '/' && src[i+1] == '/') {
            while (i < src.size() && src[i] != '\n') ++i;
        }
        if (i < src.size()) out += src[i];
    }
    return out;
}

static std::vector<uint8_t> decode_hex_byte(const std::string& tok) {
    // parse one hex byte "AB" → 0xAB
    if (tok.size() != 2) return {};
    auto nibble = [](char c) -> int {
        if (c >= '0' && c <= '9') return c - '0';
        if (c >= 'a' && c <= 'f') return 10 + c - 'a';
        if (c >= 'A' && c <= 'F') return 10 + c - 'A';
        return -1;
    };
    int hi = nibble(tok[0]), lo = nibble(tok[1]);
    if (hi < 0 || lo < 0) return {};
    return { static_cast<uint8_t>((hi << 4) | lo) };
}

// Parse hex pattern like "{ DE AD BE EF 00 ?? }"
static std::optional<PatternEntry> parse_hex_pattern(const std::string& id,
                                                       const std::string& hex_str) {
    PatternEntry pe;
    pe.identifier = id;

    std::istringstream ss(hex_str);
    std::string tok;
    while (ss >> tok) {
        if (tok == "{" || tok == "}") continue;
        if (tok == "??") {
            pe.bytes.push_back(0x00);
            pe.wildcard.push_back(true);
        } else {
            auto b = decode_hex_byte(tok);
            if (b.empty()) {
                std::cerr << "warning: invalid hex token '" << tok << "', skipping\n";
                continue;
            }
            pe.bytes.push_back(b[0]);
            pe.wildcard.push_back(false);
        }
    }
    if (pe.bytes.empty()) return std::nullopt;
    return pe;
}

// Parse string pattern like "some text"
static PatternEntry parse_string_pattern(const std::string& id,
                                          const std::string& text,
                                          bool nocase) {
    PatternEntry pe;
    pe.identifier = id;
    pe.nocase = nocase;
    for (char c : text) {
        pe.bytes.push_back(static_cast<uint8_t>(c));
        pe.wildcard.push_back(false);
    }
    return pe;
}

static std::vector<Rule> parse_rules(const std::string& src) {
    std::vector<Rule> rules;
    std::string clean = strip_comments(src);

    std::size_t pos = 0;
    auto skip_ws = [&]() {
        while (pos < clean.size() && std::isspace(static_cast<unsigned char>(clean[pos])))
            ++pos;
    };

    while (pos < clean.size()) {
        skip_ws();
        if (pos >= clean.size()) break;

        // Expect "rule"
        if (clean.substr(pos, 4) != "rule") { ++pos; continue; }
        pos += 4;
        skip_ws();

        // Rule name
        std::size_t name_start = pos;
        while (pos < clean.size() &&
               (std::isalnum(static_cast<unsigned char>(clean[pos])) || clean[pos] == '_'))
            ++pos;
        std::string rule_name = clean.substr(name_start, pos - name_start);
        if (rule_name.empty()) continue;

        skip_ws();
        if (pos >= clean.size() || clean[pos] != '{') continue;
        ++pos; // consume '{'

        // Find matching closing brace
        std::size_t depth = 1, block_start = pos;
        while (pos < clean.size() && depth > 0) {
            if (clean[pos] == '{') ++depth;
            else if (clean[pos] == '}') --depth;
            ++pos;
        }
        std::string block = clean.substr(block_start, pos - block_start - 1);

        Rule rule;
        rule.name = rule_name;

        // Find "strings:" section
        auto str_pos = block.find("strings:");
        auto cond_pos = block.find("condition:");

        if (str_pos != std::string::npos && cond_pos != std::string::npos) {
            std::string str_block = block.substr(
                str_pos + 8,
                cond_pos - str_pos - 8);

            std::istringstream ss(str_block);
            std::string line;
            while (std::getline(ss, line)) {
                // Trim leading whitespace
                std::size_t lp = 0;
                while (lp < line.size() && std::isspace(static_cast<unsigned char>(line[lp])))
                    ++lp;
                line = line.substr(lp);
                if (line.empty()) continue;

                // Pattern line: $id = "string" [nocase]
                //           or: $id = { hex bytes }
                if (line[0] != '$') continue;
                auto eq = line.find('=');
                if (eq == std::string::npos) continue;

                std::string pid = line.substr(0, eq);
                // trim trailing space from pid
                while (!pid.empty() && std::isspace(static_cast<unsigned char>(pid.back())))
                    pid.pop_back();

                std::string val = line.substr(eq + 1);
                // trim
                lp = 0;
                while (lp < val.size() && std::isspace(static_cast<unsigned char>(val[lp]))) ++lp;
                val = val.substr(lp);

                if (!val.empty() && val[0] == '"') {
                    // String pattern
                    auto close = val.find('"', 1);
                    if (close == std::string::npos) continue;
                    std::string text = val.substr(1, close - 1);
                    std::string rest = val.substr(close + 1);
                    bool nc = (rest.find("nocase") != std::string::npos);
                    rule.patterns.push_back(parse_string_pattern(pid, text, nc));
                } else if (!val.empty() && val[0] == '{') {
                    // Hex pattern — collect until closing }
                    auto close_brace = val.find('}');
                    if (close_brace == std::string::npos) continue;
                    auto opt = parse_hex_pattern(pid, val.substr(0, close_brace + 1));
                    if (opt) rule.patterns.push_back(*opt);
                }
            }
        }

        if (cond_pos != std::string::npos)
            rule.condition_raw = block.substr(cond_pos + 10);

        rules.push_back(std::move(rule));
    }
    return rules;
}

// ─────────────────────────── matching ───────────────────────────────────────

// Returns all offsets where pattern matches in data
static std::vector<std::size_t> find_pattern(
        const std::vector<uint8_t>& data,
        const PatternEntry& pat) {

    std::vector<std::size_t> offsets;
    if (pat.bytes.empty() || data.size() < pat.bytes.size()) return offsets;

    std::size_t limit = data.size() - pat.bytes.size();

    for (std::size_t i = 0; i <= limit; ++i) {
        bool match = true;
        for (std::size_t j = 0; j < pat.bytes.size(); ++j) {
            if (pat.wildcard[j]) continue;
            uint8_t d = data[i + j];
            uint8_t p = pat.bytes[j];
            if (pat.nocase) {
                d = static_cast<uint8_t>(std::tolower(d));
                p = static_cast<uint8_t>(std::tolower(p));
            }
            if (d != p) { match = false; break; }
        }
        if (match) offsets.push_back(i);
    }
    return offsets;
}

// ─────────────────────────── condition evaluator ────────────────────────────

// Simple recursive-descent evaluator for YARA-style conditions
// Supports: $id, and, or, not, ( ), any of them, all of them

struct CondEval {
    const std::string& cond;
    const std::unordered_map<std::string, bool>& matched;
    std::size_t pos = 0;

    void skip_ws() {
        while (pos < cond.size() && std::isspace(static_cast<unsigned char>(cond[pos])))
            ++pos;
    }

    std::string peek_token() {
        std::size_t save = pos;
        skip_ws();
        std::size_t start = pos;
        if (pos < cond.size() && (cond[pos] == '(' || cond[pos] == ')')) {
            ++pos;
            std::string t = cond.substr(start, 1);
            pos = save;
            return t;
        }
        while (pos < cond.size() &&
               !std::isspace(static_cast<unsigned char>(cond[pos])) &&
               cond[pos] != '(' && cond[pos] != ')') ++pos;
        std::string t = cond.substr(start, pos - start);
        pos = save;
        return t;
    }

    std::string consume_token() {
        skip_ws();
        std::size_t start = pos;
        if (pos < cond.size() && (cond[pos] == '(' || cond[pos] == ')')) {
            return std::string(1, cond[pos++]);
        }
        while (pos < cond.size() &&
               !std::isspace(static_cast<unsigned char>(cond[pos])) &&
               cond[pos] != '(' && cond[pos] != ')') ++pos;
        return cond.substr(start, pos - start);
    }

    bool eval_primary() {
        skip_ws();
        std::string tok = consume_token();
        if (tok == "(") {
            bool v = eval_or();
            consume_token(); // ')'
            return v;
        }
        if (tok == "not") return !eval_primary();
        if (tok == "any") {
            consume_token(); // "of"
            consume_token(); // "them"
            for (auto& [k, v] : matched) if (v) return true;
            return false;
        }
        if (tok == "all") {
            consume_token(); // "of"
            consume_token(); // "them"
            for (auto& [k, v] : matched) if (!v) return false;
            return !matched.empty();
        }
        if (!tok.empty() && tok[0] == '$') {
            auto it = matched.find(tok);
            return it != matched.end() && it->second;
        }
        return false;
    }

    bool eval_and() {
        bool v = eval_primary();
        while (true) {
            skip_ws();
            if (pos >= cond.size()) break;
            std::string next = peek_token();
            if (next != "and") break;
            consume_token();
            v = eval_primary() && v;
        }
        return v;
    }

    bool eval_or() {
        bool v = eval_and();
        while (true) {
            skip_ws();
            if (pos >= cond.size()) break;
            std::string next = peek_token();
            if (next != "or") break;
            consume_token();
            v = eval_and() || v;
        }
        return v;
    }

    bool evaluate() {
        pos = 0;
        return eval_or();
    }
};

// ─────────────────────────── file scanner ───────────────────────────────────

static std::vector<MatchRecord> scan_file(
        const fs::path& path,
        const std::vector<Rule>& rules) {

    // Load file into memory
    std::ifstream in(path, std::ios::binary);
    if (!in) return {};
    std::vector<uint8_t> data(
        (std::istreambuf_iterator<char>(in)),
        std::istreambuf_iterator<char>());

    std::vector<MatchRecord> matches;

    for (auto& rule : rules) {
        // For each pattern, find all matches
        std::unordered_map<std::string, bool> hit_map;
        std::vector<MatchRecord> rule_hits;

        for (auto& pat : rule.patterns) {
            auto offsets = find_pattern(data, pat);
            hit_map[pat.identifier] = !offsets.empty();

            for (auto off : offsets) {
                // Build preview (up to 8 bytes as hex)
                std::ostringstream preview;
                std::size_t preview_len = std::min(pat.bytes.size(), std::size_t(8));
                for (std::size_t k = 0; k < preview_len; ++k) {
                    if (k > 0) preview << " ";
                    preview << std::hex << std::uppercase << std::setw(2)
                            << std::setfill('0') << static_cast<int>(data[off + k]);
                }
                if (pat.bytes.size() > 8) preview << " ...";

                rule_hits.push_back({rule.name, pat.identifier, off, preview.str()});
            }
        }

        // Evaluate condition
        bool condition_met = false;
        if (rule.condition_raw.empty()) {
            // Default: any pattern must match
            for (auto& [k, v] : hit_map) if (v) { condition_met = true; break; }
        } else {
            CondEval eval{rule.condition_raw, hit_map};
            condition_met = eval.evaluate();
        }

        if (condition_met) {
            // Only report the first offset for each identifier
            std::unordered_map<std::string, bool> reported;
            for (auto& r : rule_hits) {
                if (!reported[r.identifier]) {
                    matches.push_back(r);
                    reported[r.identifier] = true;
                }
            }
        }
    }

    return matches;
}

// ─────────────────────────── main ───────────────────────────────────────────

static void usage(const char* prog) {
    std::cerr << "Usage:\n"
              << "  " << prog << " --rules <file.yrl> <target> [--recursive]\n"
              << "  " << prog << " --builtin <target> [--recursive]\n";
}

int main(int argc, char* argv[]) {
    if (argc < 3) { usage(argv[0]); return 1; }

    std::string rules_source;
    std::string target;
    bool recursive = false;

    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        if (a == "--rules") {
            if (i + 1 >= argc) { usage(argv[0]); return 1; }
            std::ifstream rf(argv[++i]);
            if (!rf) { std::cerr << "error: cannot open rules file\n"; return 1; }
            rules_source = std::string((std::istreambuf_iterator<char>(rf)),
                                        std::istreambuf_iterator<char>());
        } else if (a == "--builtin") {
            rules_source = BUILTIN_RULES;
        } else if (a == "--recursive") {
            recursive = true;
        } else {
            target = a;
        }
    }

    if (rules_source.empty() || target.empty()) { usage(argv[0]); return 1; }

    auto rules = parse_rules(rules_source);
    if (rules.empty()) {
        std::cerr << "error: no valid rules parsed\n";
        return 1;
    }
    std::cerr << "Loaded " << rules.size() << " rule(s).\n";

    fs::path root(target);
    std::vector<fs::path> targets;

    if (fs::is_regular_file(root)) {
        targets.push_back(root);
    } else if (fs::is_directory(root)) {
        if (recursive) {
            for (auto& e : fs::recursive_directory_iterator(
                     root, fs::directory_options::skip_permission_denied))
                if (fs::is_regular_file(e.path()))
                    targets.push_back(e.path());
        } else {
            for (auto& e : fs::directory_iterator(root))
                if (fs::is_regular_file(e.path()))
                    targets.push_back(e.path());
        }
    } else {
        std::cerr << "error: target not found: " << target << "\n";
        return 1;
    }

    bool any_match = false;
    int files_scanned = 0;

    for (auto& p : targets) {
        ++files_scanned;
        auto hits = scan_file(p, rules);
        if (!hits.empty()) {
            any_match = true;
            std::cout << "\n[MATCH] " << p.string() << "\n";
            for (auto& h : hits) {
                std::cout << "  Rule       : " << h.rule_name      << "\n"
                          << "  Identifier : " << h.identifier     << "\n"
                          << "  Offset     : 0x" << std::hex << std::uppercase
                                                  << h.offset       << "\n"
                          << "  Preview    : " << h.pattern_preview << "\n"
                          << std::dec;
            }
        }
    }

    std::cerr << "\nScanned " << files_scanned << " file(s). "
              << (any_match ? "Matches found." : "No matches.") << "\n";

    return any_match ? 2 : 0;
}
