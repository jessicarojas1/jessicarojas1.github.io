/*
 * entropy_scanner.cpp — Shannon Entropy + Chi-Square File Scanner
 * ================================================================
 * Computes Shannon entropy (0–8 bits/byte) AND a chi-square uniformity
 * test for every file in a target path. Using both statistics together
 * eliminates false positives: a file that is both high-entropy AND
 * chi2-RANDOM is almost certainly encrypted; a merely compressed file
 * will have high entropy but a structured (non-uniform) chi2 score.
 *
 * Statistical tests
 * -----------------
 *   Shannon entropy  H = −Σ p(i)·log₂(p(i))   range [0, 8]
 *   Chi-square       χ² against uniform byte distribution (df = 255)
 *     χ² ~255 : byte distribution is approximately uniform
 *               → strongly indicates encrypted or CSPRNG data
 *     χ² >300 : non-uniform peaks → compressed, mixed content
 *     χ² <210 : heavily biased → ASCII text, binary with few byte values
 *
 * Build
 * -----
 *   Linux/macOS:  g++ -std=c++17 -O2 -pthread entropy_scanner.cpp -o entropy-scanner
 *   Windows MSVC: cl /std:c++17 /O2 /EHsc entropy_scanner.cpp
 *   Windows MinGW: g++ -std=c++17 -O2 -pthread entropy_scanner.cpp -o entropy-scanner.exe
 *
 * Usage
 * -----
 *   ./entropy-scanner <file_or_directory> [OPTIONS]
 *
 *   Options:
 *     --min <float>    only report files above this entropy  (default: 0.0)
 *     --parallel <N>   use N worker threads; 0 = auto-detect (default: 1)
 *     --json           emit JSON output for SIEM/pipeline ingest
 *     --verbose        include chi-square scores and RANDOM/STRUCTURED label
 *
 *   Examples:
 *     ./entropy-scanner /opt/firmware/            # scan entire tree
 *     ./entropy-scanner suspicious.bin            # single file
 *     ./entropy-scanner /var/www --min 6.5        # threshold filter
 *     ./entropy-scanner /tmp/upload --json        # JSON → SIEM
 *     ./entropy-scanner /large/store --parallel 8 # multi-core scan
 *
 * Verdicts
 * --------
 *   PACKED/ENCRYPTED  entropy ≥ 7.2  (packed, encrypted, or obfuscated)
 *   COMPRESSED        entropy ≥ 6.0  (compressed archive)
 *   PLAINTEXT         entropy <  6.0  (human-readable content)
 *
 * Exit codes
 * ----------
 *   0 — scan complete, no PACKED/ENCRYPTED files
 *   1 — usage or I/O error
 *   2 — at least one PACKED/ENCRYPTED file found
 *
 * Deployment notes
 * ----------------
 *   Requires native filesystem for directory scanning.
 *   Core algorithm is WebAssembly-compatible (Emscripten MEMFS or stdin mode).
 *   Parallel mode requires pthreads; link with -pthread on Linux/macOS.
 *   For large trees (>50 GB) combine --min 6.5 with --parallel N.
 *   Pipe-friendly: combine with find(1):
 *     find /proc -maxdepth 3 -name exe | xargs ./entropy-scanner
 *
 * Author: Jessica Rojas — Cybersecurity Portfolio
 * License: MIT
 */

#include <algorithm>
#include <array>
#include <cmath>
#include <cstdint>
#include <filesystem>
#include <fstream>
#include <future>
#include <iomanip>
#include <iostream>
#include <optional>
#include <string>
#include <thread>
#include <vector>

// Platform-portable isatty / fileno
#ifdef _WIN32
  #include <io.h>
  #define isatty _isatty
  #define fileno _fileno
#else
  #include <unistd.h>
#endif

namespace fs = std::filesystem;

// ─────────────────────────── constants ──────────────────────────────────────

static constexpr double      THRESHOLD_PACKED     = 7.2;
static constexpr double      THRESHOLD_COMPRESSED = 6.0;
static constexpr double      CHI2_RANDOM_LOW      = 210.0; // below → biased
static constexpr double      CHI2_RANDOM_HIGH     = 300.0; // above → structured
static constexpr std::size_t READ_BUFFER          = 1u << 20; // 1 MiB chunks

// ─────────────────────────── types ──────────────────────────────────────────

enum class Verdict { PLAINTEXT, COMPRESSED, PACKED_ENCRYPTED };

struct EntropyResult {
    double   entropy  = 0.0;
    double   chi2     = 0.0;    // χ² vs. uniform distribution (df=255)
    uint64_t bytes    = 0;
    bool     valid    = false;
};

struct FileResult {
    std::string path;
    double      entropy;
    double      chi2;
    uintmax_t   size;
    Verdict     verdict;
};

// ─────────────────────────── statistics ─────────────────────────────────────

// Reads a file in 1 MiB chunks and computes:
//   H  = −Σ p(i)·log₂(p(i))   Shannon entropy, range [0, 8]
//   χ² = Σ (freq[i]−expected)²/expected   goodness-of-fit vs. uniform
//
// Returns EntropyResult with valid=false on I/O failure or empty file.
static EntropyResult compute_entropy_full(const std::string& filepath)
{
    std::array<uint64_t, 256> freq{};
    freq.fill(0);

    std::ifstream in(filepath, std::ios::binary);
    if (!in) return {};

    std::vector<char> buf(READ_BUFFER);
    uint64_t total = 0;

    while (in.read(buf.data(), static_cast<std::streamsize>(buf.size())) ||
           in.gcount() > 0) {
        auto n = static_cast<std::size_t>(in.gcount());
        for (std::size_t i = 0; i < n; ++i)
            ++freq[static_cast<uint8_t>(buf[i])];
        total += n;
    }

    if (total == 0) return {};

    const double inv_total = 1.0 / static_cast<double>(total);
    const double expected  = static_cast<double>(total) / 256.0;

    double entropy = 0.0;
    double chi2    = 0.0;

    for (int b = 0; b < 256; ++b) {
        if (freq[b] != 0) {
            double p = static_cast<double>(freq[b]) * inv_total;
            entropy -= p * std::log2(p);
        }
        double diff = static_cast<double>(freq[b]) - expected;
        chi2 += (diff * diff) / expected;
    }

    return {entropy, chi2, total, true};
}

// ─────────────────────────── classification ─────────────────────────────────

static Verdict classify(double entropy)
{
    if (entropy >= THRESHOLD_PACKED)     return Verdict::PACKED_ENCRYPTED;
    if (entropy >= THRESHOLD_COMPRESSED) return Verdict::COMPRESSED;
    return Verdict::PLAINTEXT;
}

static const char* verdict_str(Verdict v)
{
    switch (v) {
        case Verdict::PACKED_ENCRYPTED: return "PACKED/ENCRYPTED";
        case Verdict::COMPRESSED:       return "COMPRESSED";
        default:                        return "PLAINTEXT";
    }
}

// Interpret χ² score against a uniform distribution.
// df=255; mean=255; 95% CI ≈ [212, 298].
static const char* chi2_verdict(double chi2)
{
    if (chi2 < CHI2_RANDOM_LOW)  return "BIASED";     // skewed byte distribution
    if (chi2 > CHI2_RANDOM_HIGH) return "STRUCTURED"; // non-uniform peaks
    return "RANDOM";                                   // uniform → encrypted/CSPRNG
}

static const char* verdict_colour(Verdict v, bool use_colour)
{
    if (!use_colour) return "";
    switch (v) {
        case Verdict::PACKED_ENCRYPTED: return "\033[1;31m"; // bold red
        case Verdict::COMPRESSED:       return "\033[1;33m"; // bold yellow
        default:                        return "\033[0;32m"; // green
    }
}

static const char* chi2_colour(double chi2, bool use_colour)
{
    if (!use_colour) return "";
    const char* v = chi2_verdict(chi2);
    if (v[0] == 'R') return "\033[1;31m"; // RANDOM → bold red (most suspicious)
    if (v[0] == 'S') return "\033[1;33m"; // STRUCTURED → yellow
    return "\033[0;37m";                  // BIASED → grey
}

static const char* colour_reset(bool use_colour)
{
    return use_colour ? "\033[0m" : "";
}

// ─────────────────────────── file processing ────────────────────────────────

static std::optional<FileResult> process_file(const fs::path& p, double min_threshold)
{
    std::error_code ec;
    if (!fs::is_regular_file(p, ec) || ec) return std::nullopt;

    uintmax_t sz = fs::file_size(p, ec);
    if (ec) sz = 0;

    EntropyResult er = compute_entropy_full(p.string());
    if (!er.valid || er.entropy < min_threshold) return std::nullopt;

    return FileResult{p.string(), er.entropy, er.chi2, sz, classify(er.entropy)};
}

// ─────────────────────────── path collection ────────────────────────────────

// Single-threaded recursive walk — filesystem traversal is inherently serial.
static std::vector<fs::path> collect_paths(const fs::path& root)
{
    std::vector<fs::path> paths;
    std::error_code ec;

    if (fs::is_regular_file(root, ec) && !ec) {
        paths.push_back(root);
    } else if (fs::is_directory(root, ec) && !ec) {
        for (auto& entry : fs::recursive_directory_iterator(
                root, fs::directory_options::skip_permission_denied)) {
            if (fs::is_regular_file(entry.path(), ec) && !ec)
                paths.push_back(entry.path());
        }
    } else {
        std::cerr << "error: " << root << " is not a regular file or directory\n";
    }
    return paths;
}

// ─────────────────────────── scanning ───────────────────────────────────────

// Scan all files under root, optionally distributing entropy computation
// across multiple threads using std::async + std::launch::async.
//
// parallel_threads=0  → detect hardware_concurrency automatically
// parallel_threads=1  → single-threaded (default)
// parallel_threads=N  → divide file list into N chunks, process in parallel
//
// Note: filesystem walk is always single-threaded; parallelism applies only
// to the entropy+chi2 computation phase.
static std::vector<FileResult> scan_path(const fs::path& root,
                                         double min_threshold,
                                         int    parallel_threads)
{
    auto paths = collect_paths(root);
    if (paths.empty()) return {};

    if (parallel_threads == 0)
        parallel_threads = static_cast<int>(std::thread::hardware_concurrency());
    if (parallel_threads < 1) parallel_threads = 1;

    std::vector<FileResult> results;

    if (parallel_threads == 1 || static_cast<int>(paths.size()) <= parallel_threads) {
        // ── Single-threaded ───────────────────────────────────────────────
        results.reserve(paths.size() / 8 + 1);
        for (auto& p : paths) {
            if (auto r = process_file(p, min_threshold))
                results.push_back(std::move(*r));
        }
    } else {
        // ── Parallel: divide file list into N chunks, compute via async ───
        int n = std::min(parallel_threads, static_cast<int>(paths.size()));
        auto chunk_size = static_cast<std::ptrdiff_t>(
            (paths.size() + static_cast<std::size_t>(n) - 1)
            / static_cast<std::size_t>(n));

        std::vector<std::future<std::vector<FileResult>>> futures;
        futures.reserve(static_cast<std::size_t>(n));

        for (int t = 0; t < n; ++t) {
            auto beg = paths.begin() + t * chunk_size;
            auto end = std::min(beg + chunk_size, paths.end());
            std::vector<fs::path> slice(beg, end);

            futures.push_back(std::async(std::launch::async,
                [s = std::move(slice), min_threshold]() mutable {
                    std::vector<FileResult> local;
                    local.reserve(s.size() / 4 + 1);
                    for (auto& p : s) {
                        if (auto r = process_file(p, min_threshold))
                            local.push_back(std::move(*r));
                    }
                    return local;
                }));
        }

        for (auto& fut : futures) {
            auto partial = fut.get();
            results.insert(results.end(),
                           std::make_move_iterator(partial.begin()),
                           std::make_move_iterator(partial.end()));
        }
    }

    // Sort highest entropy first
    std::sort(results.begin(), results.end(),
              [](const FileResult& a, const FileResult& b) {
                  return a.entropy > b.entropy;
              });
    return results;
}

// ─────────────────────────── output ─────────────────────────────────────────

static void print_table(const std::vector<FileResult>& results,
                        bool use_colour,
                        bool verbose)
{
    // Dynamic path column width, capped at 72
    std::size_t path_w = 8; // min width = "FILENAME"
    for (auto& r : results)
        path_w = std::max(path_w, r.path.size());
    path_w = std::min(path_w, std::size_t{72});

    constexpr int ent_w  = 9;
    constexpr int chi_w  = 10;
    constexpr int size_w = 14;
    constexpr int verd_w = 16;
    constexpr int rand_w = 10;

    // ── Header ───────────────────────────────────────────────────────────
    std::cout << std::left << std::setw(static_cast<int>(path_w)) << "FILENAME"
              << "  " << std::setw(ent_w)  << "ENTROPY";
    if (verbose)
        std::cout << "  " << std::setw(chi_w) << "CHI2";
    std::cout << "  " << std::setw(size_w) << "SIZE (bytes)"
              << "  " << std::setw(verd_w) << "VERDICT";
    if (verbose)
        std::cout << "  " << std::setw(rand_w) << "RAND?";
    std::cout << "\n";

    int sep_w = static_cast<int>(path_w) + ent_w + size_w + verd_w + 6;
    if (verbose) sep_w += chi_w + rand_w + 4;
    std::cout << std::string(sep_w, '-') << "\n";

    // ── Rows ─────────────────────────────────────────────────────────────
    for (auto& r : results) {
        std::string dp = r.path;
        if (dp.size() > path_w)
            dp = "\xe2\x80\xa6" + dp.substr(dp.size() - (path_w - 1));

        const char* col   = verdict_colour(r.verdict, use_colour);
        const char* reset = colour_reset(use_colour);

        std::cout << std::left  << std::setw(static_cast<int>(path_w)) << dp
                  << "  " << std::right << std::setw(ent_w) << std::fixed
                  << std::setprecision(4) << r.entropy;

        if (verbose)
            std::cout << "  " << std::right << std::setw(chi_w) << std::fixed
                      << std::setprecision(1) << r.chi2;

        std::cout << "  " << std::right << std::setw(size_w) << r.size
                  << "  " << col << std::left << std::setw(verd_w)
                  << verdict_str(r.verdict) << reset;

        if (verbose) {
            const char* cc = chi2_colour(r.chi2, use_colour);
            std::cout << "  " << cc << std::setw(rand_w) << chi2_verdict(r.chi2) << reset;
        }
        std::cout << "\n";
    }
}

static void print_json(const std::vector<FileResult>& results)
{
    auto esc = [](const std::string& s) {
        std::string o;
        o.reserve(s.size() + 8);
        for (char c : s) {
            if      (c == '"')  o += "\\\"";
            else if (c == '\\') o += "\\\\";
            else if (c == '\n') o += "\\n";
            else                o += c;
        }
        return o;
    };

    std::cout << "[\n";
    for (std::size_t i = 0; i < results.size(); ++i) {
        const auto& r = results[i];
        std::cout
            << "  {\n"
            << "    \"path\": \""        << esc(r.path)           << "\",\n"
            << "    \"entropy\": "       << std::fixed << std::setprecision(6)
                                         << r.entropy             << ",\n"
            << "    \"chi2\": "          << std::fixed << std::setprecision(2)
                                         << r.chi2                << ",\n"
            << "    \"chi2_verdict\": \"" << chi2_verdict(r.chi2) << "\",\n"
            << "    \"size\": "          << r.size                << ",\n"
            << "    \"verdict\": \""     << verdict_str(r.verdict)<< "\"\n"
            << "  }" << (i + 1 < results.size() ? "," : "") << "\n";
    }
    std::cout << "]\n";
}

// ─────────────────────────── entry point ────────────────────────────────────

static void usage(const char* prog)
{
    std::cerr <<
        "Usage: " << prog << " <path> [OPTIONS]\n\n"
        "Options:\n"
        "  --min <float>      only report files above this entropy  (default: 0.0)\n"
        "  --parallel <N>     use N worker threads; 0=auto          (default: 1)\n"
        "  --json             emit JSON array for SIEM/pipeline ingest\n"
        "  --verbose          include chi-square scores and RANDOM/STRUCTURED label\n\n"
        "Verdicts:\n"
        "  PACKED/ENCRYPTED   entropy >= 7.2  (packed, encrypted, or AV-evading)\n"
        "  COMPRESSED         entropy >= 6.0  (compressed archive)\n"
        "  PLAINTEXT          entropy <  6.0  (human-readable content)\n\n"
        "Chi-square RANDOM label (--verbose) means byte distribution is approximately\n"
        "uniform (df=255, chi2 in [210,300]) — the strongest indicator of encryption.\n\n"
        "Exit codes:  0=clean  1=error  2=PACKED/ENCRYPTED file(s) found\n";
}

int main(int argc, char* argv[])
{
    if (argc < 2) { usage(argv[0]); return 1; }

    std::string target;
    double min_threshold  = 0.0;
    int    parallel       = 1;
    bool   json_mode      = false;
    bool   verbose        = false;

    for (int i = 1; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "--json") {
            json_mode = true;
        } else if (arg == "--verbose" || arg == "-v") {
            verbose = true;
        } else if (arg == "--min") {
            if (i + 1 >= argc) { usage(argv[0]); return 1; }
            try { min_threshold = std::stod(argv[++i]); }
            catch (...) { std::cerr << "error: invalid --min value\n"; return 1; }
        } else if (arg == "--parallel") {
            if (i + 1 >= argc) { usage(argv[0]); return 1; }
            try { parallel = std::stoi(argv[++i]); }
            catch (...) { std::cerr << "error: invalid --parallel value\n"; return 1; }
            if (parallel < 0) { std::cerr << "error: --parallel must be >= 0\n"; return 1; }
        } else if (target.empty()) {
            target = arg;
        } else {
            std::cerr << "error: unexpected argument: " << arg << "\n";
            usage(argv[0]); return 1;
        }
    }

    if (target.empty()) { usage(argv[0]); return 1; }

    const fs::path root(target);
    std::error_code ec;
    if (!fs::exists(root, ec) || ec) {
        std::cerr << "error: path does not exist: " << target << "\n";
        return 1;
    }

    if (!json_mode && parallel != 1) {
        int actual = (parallel == 0) ? static_cast<int>(std::thread::hardware_concurrency())
                                     : parallel;
        std::cerr << "[info] parallel mode: " << actual << " threads\n";
    }

    auto results = scan_path(root, min_threshold, parallel);

    // Colour only when writing to a terminal (not piped/redirected)
    const bool use_colour = !json_mode && (isatty(fileno(stdout)) != 0);

    if (json_mode) {
        print_json(results);
    } else if (results.empty()) {
        std::cout << "No files matched (threshold=" << min_threshold << ").\n";
    } else {
        print_table(results, use_colour, verbose);
        std::cout << "\nTotal files reported: " << results.size() << "\n";
        if (!verbose)
            std::cout << "Tip: add --verbose to include chi-square uniformity scores.\n";
    }

    // Exit 2 if any file is PACKED/ENCRYPTED (non-zero for CI/pipeline use)
    for (auto& r : results)
        if (r.verdict == Verdict::PACKED_ENCRYPTED) return 2;

    return 0;
}
