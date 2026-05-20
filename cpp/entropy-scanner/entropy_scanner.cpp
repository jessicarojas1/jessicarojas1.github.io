/*
 * entropy_scanner.cpp — Shannon Entropy File Scanner
 *
 * Description:
 *   Computes Shannon entropy (0–8 bits/byte) for every file in a target path.
 *   High entropy often indicates packed executables, encrypted payloads, or
 *   compressed archives — all common obfuscation techniques used by malware.
 *
 * Build:
 *   g++ -std=c++17 -O2 entropy_scanner.cpp -o entropy-scanner
 *
 * Usage:
 *   ./entropy-scanner <file_or_directory> [--min <threshold>] [--json]
 *
 *   ./entropy-scanner /opt/firmware/           # scan entire directory tree
 *   ./entropy-scanner suspicious.bin           # single file
 *   ./entropy-scanner /var/www --min 6.5       # report only above threshold
 *   ./entropy-scanner /tmp/upload --json       # JSON output for SIEM ingest
 *
 * Verdicts:
 *   PACKED/ENCRYPTED  entropy >= 7.2
 *   COMPRESSED        entropy >= 6.0
 *   PLAINTEXT         entropy <  6.0
 *
 * Exit codes:
 *   0  — scan complete, no high-entropy files found
 *   1  — usage / I/O error
 *   2  — at least one PACKED/ENCRYPTED file found
 *
 * Deployment notes:
 *   Requires a native filesystem. Not usable in WebAssembly sandboxes.
 *   Large directories (>50 GB) may require --min to reduce output noise.
 *   Combine with find(1) piping for targeted scans:
 *     find /proc/ -maxdepth 3 -name exe | xargs ./entropy-scanner
 */

#include <algorithm>
#include <array>
#include <cmath>
#include <cstdint>
#include <filesystem>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <string>
#include <unistd.h>
#include <vector>

namespace fs = std::filesystem;

// ─────────────────────────── constants ──────────────────────────────────────

static constexpr double THRESHOLD_PACKED     = 7.2;
static constexpr double THRESHOLD_COMPRESSED = 6.0;
static constexpr std::size_t READ_BUFFER     = 1u << 20; // 1 MiB

// ─────────────────────────── types ──────────────────────────────────────────

enum class Verdict { PLAINTEXT, COMPRESSED, PACKED_ENCRYPTED };

struct FileResult {
    std::string path;
    double      entropy;
    uintmax_t   size;
    Verdict     verdict;
};

// ─────────────────────────── entropy math ───────────────────────────────────

// Computes Shannon entropy H = -Σ p(i) * log2(p(i)) over byte frequency
// Returns a value in [0.0, 8.0].
double compute_entropy(const std::string& filepath) {
    std::array<uint64_t, 256> freq{};
    freq.fill(0);

    std::ifstream in(filepath, std::ios::binary);
    if (!in) return -1.0;

    std::vector<char> buf(READ_BUFFER);
    uint64_t total = 0;

    while (in.read(buf.data(), static_cast<std::streamsize>(buf.size())) ||
           in.gcount() > 0) {
        auto n = static_cast<std::size_t>(in.gcount());
        for (std::size_t i = 0; i < n; ++i)
            ++freq[static_cast<uint8_t>(buf[i])];
        total += n;
    }

    if (total == 0) return 0.0;

    double entropy = 0.0;
    for (int b = 0; b < 256; ++b) {
        if (freq[b] == 0) continue;
        double p = static_cast<double>(freq[b]) / static_cast<double>(total);
        entropy -= p * std::log2(p);
    }
    return entropy;
}

// ─────────────────────────── verdict ────────────────────────────────────────

Verdict classify(double entropy) {
    if (entropy >= THRESHOLD_PACKED)     return Verdict::PACKED_ENCRYPTED;
    if (entropy >= THRESHOLD_COMPRESSED) return Verdict::COMPRESSED;
    return Verdict::PLAINTEXT;
}

const char* verdict_str(Verdict v) {
    switch (v) {
        case Verdict::PACKED_ENCRYPTED: return "PACKED/ENCRYPTED";
        case Verdict::COMPRESSED:       return "COMPRESSED";
        default:                        return "PLAINTEXT";
    }
}

// ANSI colour codes (only when stdout is a tty)
const char* verdict_colour(Verdict v, bool use_colour) {
    if (!use_colour) return "";
    switch (v) {
        case Verdict::PACKED_ENCRYPTED: return "\033[1;31m"; // bold red
        case Verdict::COMPRESSED:       return "\033[1;33m"; // bold yellow
        default:                        return "\033[0;32m"; // green
    }
}
const char* colour_reset(bool use_colour) {
    return use_colour ? "\033[0m" : "";
}

// ─────────────────────────── scanning ───────────────────────────────────────

std::vector<FileResult> scan_path(const fs::path& root, double min_threshold) {
    std::vector<FileResult> results;

    auto process = [&](const fs::path& p) {
        std::error_code ec;
        if (!fs::is_regular_file(p, ec) || ec) return;

        uintmax_t sz = fs::file_size(p, ec);
        if (ec) sz = 0;

        double ent = compute_entropy(p.string());
        if (ent < 0.0) return; // unreadable

        if (ent < min_threshold) return;

        Verdict v = classify(ent);
        results.push_back({p.string(), ent, sz, v});
    };

    if (fs::is_regular_file(root)) {
        process(root);
    } else if (fs::is_directory(root)) {
        for (auto& entry : fs::recursive_directory_iterator(
                 root, fs::directory_options::skip_permission_denied)) {
            process(entry.path());
        }
    } else {
        std::cerr << "error: " << root << " is not a file or directory\n";
    }

    // Sort: highest entropy first
    std::sort(results.begin(), results.end(),
              [](const FileResult& a, const FileResult& b) {
                  return a.entropy > b.entropy;
              });
    return results;
}

// ─────────────────────────── output ─────────────────────────────────────────

void print_table(const std::vector<FileResult>& results, bool use_colour) {
    // Determine column widths dynamically
    std::size_t path_w = 8; // "FILENAME"
    for (auto& r : results)
        path_w = std::max(path_w, r.path.size());
    path_w = std::min(path_w, std::size_t(80)); // cap at 80 chars

    const int ent_w  = 9;
    const int size_w = 14;
    const int verd_w = 16;

    // Header
    std::cout << std::left
              << std::setw(static_cast<int>(path_w)) << "FILENAME"
              << "  " << std::setw(ent_w)  << "ENTROPY"
              << "  " << std::setw(size_w) << "SIZE (bytes)"
              << "  " << "VERDICT\n";
    std::cout << std::string(path_w + ent_w + size_w + verd_w + 6, '-') << "\n";

    for (auto& r : results) {
        std::string display_path = r.path;
        if (display_path.size() > path_w)
            display_path = "…" + display_path.substr(display_path.size() - (path_w - 1));

        const char* col   = verdict_colour(r.verdict, use_colour);
        const char* reset = colour_reset(use_colour);

        std::cout << std::left  << std::setw(static_cast<int>(path_w)) << display_path
                  << "  " << std::right << std::setw(ent_w) << std::fixed
                  << std::setprecision(4) << r.entropy
                  << "  " << std::right << std::setw(size_w) << r.size
                  << "  " << col << std::left << verdict_str(r.verdict) << reset << "\n";
    }
}

void print_json(const std::vector<FileResult>& results) {
    auto escape_json = [](const std::string& s) {
        std::string out;
        out.reserve(s.size() + 8);
        for (char c : s) {
            if (c == '"')  { out += "\\\""; }
            else if (c == '\\') { out += "\\\\"; }
            else if (c == '\n') { out += "\\n"; }
            else           { out += c; }
        }
        return out;
    };

    std::cout << "[\n";
    for (std::size_t i = 0; i < results.size(); ++i) {
        auto& r = results[i];
        std::cout << "  {\n"
                  << "    \"path\": \""    << escape_json(r.path) << "\",\n"
                  << "    \"entropy\": "   << std::fixed << std::setprecision(6) << r.entropy << ",\n"
                  << "    \"size\": "      << r.size << ",\n"
                  << "    \"verdict\": \"" << verdict_str(r.verdict) << "\"\n"
                  << "  }" << (i + 1 < results.size() ? "," : "") << "\n";
    }
    std::cout << "]\n";
}

// ─────────────────────────── main ───────────────────────────────────────────

static void usage(const char* prog) {
    std::cerr << "Usage: " << prog
              << " <path> [--min <threshold>] [--json]\n"
              << "  --min <float>   only report files above this entropy (default: 0.0)\n"
              << "  --json          emit JSON instead of a table\n";
}

int main(int argc, char* argv[]) {
    if (argc < 2) { usage(argv[0]); return 1; }

    std::string target;
    double min_threshold = 0.0;
    bool   json_mode     = false;

    for (int i = 1; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "--json") {
            json_mode = true;
        } else if (arg == "--min") {
            if (i + 1 >= argc) { usage(argv[0]); return 1; }
            try { min_threshold = std::stod(argv[++i]); }
            catch (...) { std::cerr << "error: invalid --min value\n"; return 1; }
        } else if (target.empty()) {
            target = arg;
        } else {
            std::cerr << "error: unexpected argument: " << arg << "\n";
            usage(argv[0]); return 1;
        }
    }

    if (target.empty()) { usage(argv[0]); return 1; }

    fs::path root(target);
    if (!fs::exists(root)) {
        std::cerr << "error: path does not exist: " << target << "\n";
        return 1;
    }

    std::vector<FileResult> results = scan_path(root, min_threshold);

    // Determine if stdout is a tty for colour output
    bool use_colour = !json_mode && (isatty(fileno(stdout)) != 0);

    if (json_mode) {
        print_json(results);
    } else {
        if (results.empty()) {
            std::cout << "No files matched (threshold=" << min_threshold << ").\n";
        } else {
            print_table(results, use_colour);
            std::cout << "\nTotal files reported: " << results.size() << "\n";
        }
    }

    // Exit 2 if any PACKED/ENCRYPTED files were found
    for (auto& r : results)
        if (r.verdict == Verdict::PACKED_ENCRYPTED) return 2;

    return 0;
}
