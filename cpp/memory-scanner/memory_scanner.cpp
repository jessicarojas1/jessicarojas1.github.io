/*
 * memory_scanner.cpp — Linux Process Memory IOC Scanner
 * =======================================================
 * Scans live process memory for Indicators of Compromise (IOCs) by reading
 * /proc/<pid>/maps and /proc/<pid>/mem directly.  Supports both a built-in
 * IOC pattern set and an external IOC file (one string per line).
 *
 * NOT WASM-compatible — requires Linux /proc filesystem and optional ptrace.
 *
 * Built-in IOC patterns (string / byte sequence):
 *   - Meterpreter shellcode magic bytes
 *   - Cobalt Strike beacon markers
 *   - Common C2 domain patterns
 *   - Mimikatz credential-dump strings
 *   - Reflective DLL injection markers
 *
 * Scanning strategy:
 *   - Parse /proc/<pid>/maps to enumerate readable (r) regions.
 *   - Skip [vvar], [vsyscall] (un-readable by design).
 *   - Read each region from /proc/<pid>/mem via pread(2).
 *   - Search each region for every IOC using a simple Boyer-Moore-Horspool.
 *   - On match: print region, IOC, byte offset, hex+ASCII context dump.
 *   - Optionally PTRACE_ATTACH before scanning (requires root / CAP_PTRACE)
 *     to suspend the target and get a consistent memory snapshot.
 *
 * Usage:
 *   memory-scanner --pid <PID> [--ioc <ioc_file>] [--ptrace] [--context N]
 *   memory-scanner --help
 *
 * Build (Linux only):
 *   g++ -std=c++17 -O2 memory_scanner.cpp -o memory-scanner
 *
 * Author: Jessica Rojas — Systems & Zero-Trust Portfolio
 * License: MIT
 */

#include <algorithm>
#include <array>
#include <cstdint>
#include <cstring>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <sstream>
#include <stdexcept>
#include <string>
#include <vector>

// ── Linux-only guard ──────────────────────────────────────────────────────────
#ifndef __linux__
static_assert(false,
    "memory_scanner.cpp requires Linux (/proc filesystem and ptrace). "
    "Compile only on Linux.");
#endif

#include <fcntl.h>
#include <sys/ptrace.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <unistd.h>

// ── Data types ────────────────────────────────────────────────────────────────

struct MemRegion {
    uintptr_t   start = 0;
    uintptr_t   end   = 0;
    std::string perms;      // "rwxp" etc.
    std::string label;      // e.g. "[heap]", "/usr/lib/libc.so.6", ""
};

struct Match {
    uintptr_t   region_start;
    uintptr_t   region_end;
    std::string region_label;
    uintptr_t   offset;         // offset within the region
    uintptr_t   abs_addr;       // region_start + offset
    std::string ioc_name;
    std::vector<uint8_t> pattern;
    std::vector<uint8_t> context_bytes; // surrounding bytes for dump
};

// ── Built-in IOC table ────────────────────────────────────────────────────────

struct IOC {
    std::string         name;
    std::vector<uint8_t> pattern;
};

static std::vector<IOC> builtin_iocs()
{
    std::vector<IOC> iocs;

    // Meterpreter — Windows reverse TCP shellcode prefix
    iocs.push_back({"Meterpreter-win-reverse-tcp",
        {0xfc, 0xe8, 0x89, 0x00, 0x00, 0x00, 0x60, 0x89, 0xe5}});

    // Meterpreter — x64 egghunter / stub
    iocs.push_back({"Meterpreter-x64-stub",
        {0x48, 0x31, 0xc9, 0x48, 0x81, 0xe9, 0xb0, 0xff, 0xff, 0xff}});

    // Cobalt Strike — default beacon watermark string
    iocs.push_back({"CobaltStrike-beacon-config-marker",
        {'M','Z',0x90,0x00,0x03,0x00,0x00,0x00,0x04,0x00}});

    // Cobalt Strike — named pipe default prefix
    iocs.push_back({"CobaltStrike-pipe-msagent",
        {'\\','\\','.','\\','p','i','p','e','\\','m','s','a','g','e','n','t','_'}});

    // Mimikatz — sekurlsa module marker
    iocs.push_back({"Mimikatz-sekurlsa",
        {'s','e','k','u','r','l','s','a',':',':'}});

    // Mimikatz — lsadump marker
    iocs.push_back({"Mimikatz-lsadump",
        {'l','s','a','d','u','m','p',':',':'}});

    // Mimikatz — privilege::debug
    iocs.push_back({"Mimikatz-privilege-debug",
        {'p','r','i','v','i','l','e','g','e',':',':','d','e','b','u','g'}});

    // Reflective DLL injection — ReflectiveLoader export name
    iocs.push_back({"ReflectiveDLL-loader-string",
        {'R','e','f','l','e','c','t','i','v','e','L','o','a','d','e','r'}});

    // Reflective DLL — DLL magic in anonymous mapping
    iocs.push_back({"ReflectiveDLL-MZ-in-heap",
        {'M','Z','R','E'}});   // "MZRE" uncommon MZ variant used by some loaders

    // Common C2 domain pattern: raw ".onion" in memory
    iocs.push_back({"C2-onion-domain",
        {'.','o','n','i','o','n','\x00'}});

    // Shellcode NOP sled — 32+ consecutive 0x90
    // Represented as a pattern of 8 bytes (the correlator checks runs)
    iocs.push_back({"NOP-sled-8",
        {0x90,0x90,0x90,0x90,0x90,0x90,0x90,0x90,
         0x90,0x90,0x90,0x90,0x90,0x90,0x90,0x90}});

    // Empire PowerShell stager marker
    iocs.push_back({"Empire-stager",
        {'G','r','a','n','t','e','d','A','c','c','e','s','s'}});

    // /bin/sh string placed by shellcode
    iocs.push_back({"shellcode-binsh",
        {'/','\0','b','i','n','\0','s','h','\0'}});

    // Linux memfd_create anonymous file (fileless malware)
    iocs.push_back({"memfd-create-string",
        {'m','e','m','f','d',':','/'}});

    return iocs;
}

// ── Boyer-Moore-Horspool search ───────────────────────────────────────────────

// Returns first match offset or (size_t)-1
static size_t bmh_search(const uint8_t* haystack, size_t hlen,
                         const uint8_t* needle,   size_t nlen)
{
    if (nlen == 0 || nlen > hlen) return static_cast<size_t>(-1);

    // Build bad-character skip table
    size_t skip[256];
    for (auto& s : skip) s = nlen;
    for (size_t i = 0; i < nlen - 1; ++i)
        skip[needle[i]] = nlen - 1 - i;

    size_t pos = 0;
    while (pos + nlen <= hlen) {
        size_t j = nlen - 1;
        while (j < nlen && haystack[pos + j] == needle[j]) {
            if (j == 0) return pos;
            --j;
        }
        pos += skip[haystack[pos + nlen - 1]];
    }
    return static_cast<size_t>(-1);
}

// ── /proc parsing ─────────────────────────────────────────────────────────────

static std::vector<MemRegion> parse_maps(pid_t pid)
{
    std::string maps_path = "/proc/" + std::to_string(pid) + "/maps";
    std::ifstream f(maps_path);
    if (!f) throw std::runtime_error("Cannot open " + maps_path +
                                     " — check PID and permissions.");

    std::vector<MemRegion> regions;
    for (std::string line; std::getline(f, line); ) {
        if (line.empty()) continue;
        MemRegion r;
        std::istringstream iss(line);
        std::string addrs, perms, offset, dev, inode;
        iss >> addrs >> perms >> offset >> dev >> inode;

        auto dash = addrs.find('-');
        if (dash == std::string::npos) continue;
        r.start = std::stoull(addrs.substr(0, dash),  nullptr, 16);
        r.end   = std::stoull(addrs.substr(dash + 1), nullptr, 16);
        r.perms = perms;

        // Remainder is the label (path or [heap] etc.)
        std::string rest;
        std::getline(iss, rest);
        size_t a = rest.find_first_not_of(" \t");
        if (a != std::string::npos) r.label = rest.substr(a);

        regions.push_back(r);
    }
    return regions;
}

// ── Hex dump helper ───────────────────────────────────────────────────────────

static void hex_dump(const uint8_t* data, size_t len, uintptr_t base_addr)
{
    for (size_t i = 0; i < len; i += 16) {
        std::cout << "    " << std::hex << std::setw(16) << std::setfill('0')
                  << (base_addr + i) << "  ";
        for (size_t j = 0; j < 16; ++j) {
            if (i + j < len)
                std::cout << std::setw(2) << static_cast<unsigned>(data[i + j]) << " ";
            else
                std::cout << "   ";
            if (j == 7) std::cout << " ";
        }
        std::cout << " |";
        for (size_t j = 0; j < 16 && i + j < len; ++j) {
            char c = static_cast<char>(data[i + j]);
            std::cout << (c >= 0x20 && c < 0x7F ? c : '.');
        }
        std::cout << "|\n";
    }
    std::cout << std::dec;
}

// ── Scanning logic ────────────────────────────────────────────────────────────

static std::vector<Match> scan_region(int mem_fd,
                                      const MemRegion& region,
                                      const std::vector<IOC>& iocs,
                                      size_t context_bytes)
{
    size_t sz = region.end - region.start;
    if (sz == 0 || sz > 256 * 1024 * 1024)  // skip implausibly large regions
        return {};

    std::vector<uint8_t> buf(sz);
    ssize_t n = pread(mem_fd, buf.data(), sz,
                      static_cast<off_t>(region.start));
    if (n <= 0) return {};
    size_t readable = static_cast<size_t>(n);

    std::vector<Match> hits;

    for (auto& ioc : iocs) {
        if (ioc.pattern.empty()) continue;
        size_t search_start = 0;
        while (search_start < readable) {
            size_t pos = bmh_search(buf.data() + search_start,
                                    readable   - search_start,
                                    ioc.pattern.data(),
                                    ioc.pattern.size());
            if (pos == static_cast<size_t>(-1)) break;
            pos += search_start;

            Match m;
            m.region_start = region.start;
            m.region_end   = region.end;
            m.region_label = region.label;
            m.offset       = pos;
            m.abs_addr     = region.start + pos;
            m.ioc_name     = ioc.name;
            m.pattern      = ioc.pattern;

            // Extract context window
            size_t ctx_start = (pos >= context_bytes) ? pos - context_bytes : 0;
            size_t ctx_end   = std::min(readable, pos + ioc.pattern.size() + context_bytes);
            m.context_bytes.assign(buf.begin() + static_cast<ptrdiff_t>(ctx_start),
                                   buf.begin() + static_cast<ptrdiff_t>(ctx_end));
            // Adjust abs addr for the context window start
            m.abs_addr = region.start + ctx_start;  // repoint to context start for dump

            hits.push_back(std::move(m));

            search_start = pos + ioc.pattern.size();  // advance past this match
        }
    }
    return hits;
}

// ── User IOC file loader ──────────────────────────────────────────────────────

static std::vector<IOC> load_ioc_file(const std::string& path)
{
    std::ifstream f(path);
    if (!f) throw std::runtime_error("Cannot open IOC file: " + path);
    std::vector<IOC> iocs;
    for (std::string line; std::getline(f, line); ) {
        // Strip leading/trailing whitespace
        size_t a = line.find_first_not_of(" \t\r\n");
        if (a == std::string::npos) continue;
        size_t b = line.find_last_not_of(" \t\r\n");
        line = line.substr(a, b - a + 1);
        if (line.empty() || line[0] == '#') continue;

        IOC ioc;
        ioc.name = line;
        ioc.pattern.assign(line.begin(), line.end());
        iocs.push_back(std::move(ioc));
    }
    return iocs;
}

// ── PTRACE helpers ────────────────────────────────────────────────────────────

static bool ptrace_attach(pid_t pid)
{
    if (ptrace(PTRACE_ATTACH, pid, nullptr, nullptr) == -1) {
        std::cerr << "Warning: PTRACE_ATTACH failed for PID " << pid
                  << " (" << strerror(errno) << ") — scanning live (inconsistent) memory.\n";
        return false;
    }
    int wstatus = 0;
    waitpid(pid, &wstatus, 0);
    return true;
}

static void ptrace_detach(pid_t pid)
{
    ptrace(PTRACE_DETACH, pid, nullptr, nullptr);
}

// ── main ──────────────────────────────────────────────────────────────────────

static void usage(const char* prog)
{
    std::cerr << "Usage: " << prog
              << " --pid <PID> [--ioc <file>] [--ptrace] [--context <bytes>]\n\n"
              << "  --pid     <PID>    Target process ID (required)\n"
              << "  --ioc     <file>   File of IOC strings, one per line\n"
              << "  --ptrace           PTRACE_ATTACH to suspend process (needs root)\n"
              << "  --context <N>      Hex-dump context bytes around match (default 32)\n\n"
              << "Built-in IOCs: Meterpreter, Cobalt Strike, Mimikatz,\n"
              << "               Reflective DLL, Empire, NOP sleds, memfd.\n";
}

int main(int argc, char* argv[])
{
    pid_t       target_pid   = 0;
    std::string ioc_file;
    bool        use_ptrace   = false;
    size_t      context_sz   = 32;

    for (int i = 1; i < argc; ++i) {
        std::string arg = argv[i];
        if (arg == "--pid"     && i + 1 < argc) target_pid  = std::stoi(argv[++i]);
        else if (arg == "--ioc"     && i + 1 < argc) ioc_file    = argv[++i];
        else if (arg == "--context" && i + 1 < argc) context_sz  = std::stoul(argv[++i]);
        else if (arg == "--ptrace") use_ptrace = true;
        else if (arg == "--help")   { usage(argv[0]); return 0; }
        else { std::cerr << "Unknown argument: " << arg << "\n"; usage(argv[0]); return 1; }
    }

    if (target_pid <= 0) { usage(argv[0]); return 1; }

    try {
        // Build IOC set
        auto iocs = builtin_iocs();
        if (!ioc_file.empty()) {
            auto user_iocs = load_ioc_file(ioc_file);
            std::cout << "Loaded " << user_iocs.size() << " user IOC(s) from " << ioc_file << "\n";
            iocs.insert(iocs.end(), user_iocs.begin(), user_iocs.end());
        }
        std::cout << "Total IOC patterns: " << iocs.size() << "\n";

        // Optionally attach
        bool attached = false;
        if (use_ptrace) {
            attached = ptrace_attach(target_pid);
            if (attached) std::cout << "PTRACE_ATTACH: process suspended.\n";
        }

        // Parse memory map
        auto regions = parse_maps(target_pid);
        std::cout << "Memory regions: " << regions.size() << "\n";

        // Open /proc/<pid>/mem
        std::string mem_path = "/proc/" + std::to_string(target_pid) + "/mem";
        int mem_fd = open(mem_path.c_str(), O_RDONLY);
        if (mem_fd < 0) {
            if (attached) ptrace_detach(target_pid);
            throw std::runtime_error("Cannot open " + mem_path +
                                     " — root or CAP_PTRACE required.");
        }

        // Scan
        size_t total_matches = 0;
        size_t total_bytes   = 0;

        for (auto& region : regions) {
            // Only scan readable regions; skip kernel-special regions
            if (region.perms.empty() || region.perms[0] != 'r') continue;
            if (region.label == "[vvar]"    ||
                region.label == "[vsyscall]")  continue;

            size_t sz = region.end - region.start;
            total_bytes += sz;

            auto hits = scan_region(mem_fd, region, iocs, context_sz);
            if (hits.empty()) continue;

            for (auto& m : hits) {
                ++total_matches;
                std::cout << "\n[MATCH] IOC: " << m.ioc_name << "\n"
                          << "  PID        : " << target_pid << "\n"
                          << "  Region     : 0x" << std::hex << m.region_start
                          << " – 0x" << m.region_end << std::dec << "\n"
                          << "  Label      : " << (m.region_label.empty() ? "<anonymous>" : m.region_label) << "\n"
                          << "  Perms      : " << region.perms << "\n"
                          << "  Match addr : 0x" << std::hex
                          << (m.abs_addr + context_sz)   // abs_addr was set to ctx_start
                          << std::dec << "\n"
                          << "  Pattern    : ";
                for (uint8_t b : m.pattern)
                    std::cout << std::hex << std::setw(2) << std::setfill('0')
                              << static_cast<unsigned>(b) << " ";
                std::cout << std::dec << "\n"
                          << "  Context dump (±" << context_sz << " bytes):\n";
                hex_dump(m.context_bytes.data(), m.context_bytes.size(), m.abs_addr);
            }
        }

        close(mem_fd);
        if (attached) {
            ptrace_detach(target_pid);
            std::cout << "\nPTRACE_DETACH: process resumed.\n";
        }

        std::cout << "\nScan complete — "
                  << (total_bytes / 1024) << " KiB scanned, "
                  << total_matches << " match(es) found.\n";

    } catch (const std::exception& ex) {
        std::cerr << "Error: " << ex.what() << "\n";
        return 2;
    }
    return 0;
}
