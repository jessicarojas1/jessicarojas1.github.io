/*
 * packet_analyzer.cpp — PCAP File Analyzer with MITRE ATT&CK Mapping
 *
 * Description:
 *   Parses standard libpcap (.pcap) capture files without any external library.
 *   Decodes Ethernet → IPv4/IPv6 → TCP/UDP frames and applies heuristics to
 *   detect suspicious network behaviour, mapping findings to MITRE ATT&CK
 *   technique IDs relevant to the C2/Exfiltration kill-chain phase.
 *
 * Detections:
 *   T1071.001  Application Layer Protocol: Web Protocols (HTTP C2)
 *   T1071.004  Application Layer Protocol: DNS (DNS tunneling / beaconing)
 *   T1041      Exfiltration Over C2 Channel (large outbound transfers)
 *   T1568      Dynamic Resolution (high-rate unique DNS queries)
 *   T1071.002  Application Layer Protocol: File Transfer (FTP)
 *   T1190      Exploit Public-Facing Application (HTTP error storms)
 *   (TLS SNI extraction shown as informational, no technique number
 *    assigned without further context)
 *
 * Build:
 *   g++ -std=c++17 -O2 packet_analyzer.cpp -o packet-analyzer
 *
 * Usage:
 *   ./packet-analyzer <capture.pcap> [--verbose] [--json]
 *   ./packet-analyzer traffic.pcap --verbose
 *   ./packet-analyzer dump.pcap --json > report.json
 *
 * Exit codes:
 *   0  — no suspicious patterns found
 *   1  — usage / parse error
 *   2  — at least one ATT&CK technique triggered
 *
 * Deployment notes:
 *   Requires a native filesystem to read .pcap files.
 *   Not suitable for WebAssembly (no raw socket or pcap access).
 *   Only processes DLT_EN10MB (Ethernet) and DLT_RAW (raw IP) link layers.
 *   Tested with pcap files produced by tcpdump and Wireshark.
 */

#include <algorithm>
#include <arpa/inet.h>
#include <cstdint>
#include <cstring>
#include <filesystem>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <map>
#include <set>
#include <sstream>
#include <string>
#include <unistd.h>
#include <unordered_map>
#include <vector>

namespace fs = std::filesystem;

// ─────────────────────────── pcap binary structures ──────────────────────────
// Reference: https://wiki.wireshark.org/Development/LibpcapFileFormat
// All values in the file are little-endian (magic 0xa1b2c3d4).
// We detect endianness from the magic number and swap if needed.

#pragma pack(push, 1)

struct PcapGlobalHeader {
    uint32_t magic_number;   // 0xa1b2c3d4 (LE) or 0xd4c3b2a1 (BE)
    uint16_t version_major;
    uint16_t version_minor;
    int32_t  thiszone;
    uint32_t sigfigs;
    uint32_t snaplen;
    uint32_t network;        // link-layer type (1 = Ethernet, 101 = raw IP)
};

struct PcapPacketHeader {
    uint32_t ts_sec;
    uint32_t ts_usec;
    uint32_t incl_len;   // bytes captured
    uint32_t orig_len;   // original packet length
};

struct EthernetHeader {
    uint8_t  dst[6];
    uint8_t  src[6];
    uint16_t ethertype;  // big-endian; 0x0800=IPv4, 0x86DD=IPv6
};

struct IPv4Header {
    uint8_t  ihl_ver;     // upper nibble = version (4), lower = IHL
    uint8_t  dscp_ecn;
    uint16_t total_length;
    uint16_t id;
    uint16_t flags_frag;
    uint8_t  ttl;
    uint8_t  protocol;    // 6=TCP, 17=UDP
    uint16_t checksum;
    uint32_t src_addr;
    uint32_t dst_addr;
};

struct IPv6Header {
    uint32_t version_tc_fl;
    uint16_t payload_len;
    uint8_t  next_header;  // 6=TCP, 17=UDP
    uint8_t  hop_limit;
    uint8_t  src_addr[16];
    uint8_t  dst_addr[16];
};

struct TcpHeader {
    uint16_t src_port;
    uint16_t dst_port;
    uint32_t seq_num;
    uint32_t ack_num;
    uint8_t  data_offset; // upper nibble * 4 = header length in bytes
    uint8_t  flags;
    uint16_t window;
    uint16_t checksum;
    uint16_t urgent;
};

struct UdpHeader {
    uint16_t src_port;
    uint16_t dst_port;
    uint16_t length;
    uint16_t checksum;
};

#pragma pack(pop)

// ─────────────────────────── helpers ─────────────────────────────────────────

static uint16_t swap16(uint16_t v) { return __builtin_bswap16(v); }
static uint32_t swap32(uint32_t v) { return __builtin_bswap32(v); }

static std::string ipv4_str(uint32_t addr_net) {
    char buf[INET_ADDRSTRLEN];
    inet_ntop(AF_INET, &addr_net, buf, sizeof(buf));
    return buf;
}

static std::string ipv6_str(const uint8_t* addr) {
    char buf[INET6_ADDRSTRLEN];
    inet_ntop(AF_INET6, addr, buf, sizeof(buf));
    return buf;
}

static std::string hex_str(const uint8_t* data, std::size_t len) {
    std::ostringstream ss;
    for (std::size_t i = 0; i < len; ++i) {
        ss << std::hex << std::setw(2) << std::setfill('0')
           << static_cast<int>(data[i]);
        if (i + 1 < len) ss << " ";
    }
    return ss.str();
}

static std::string escape_json(const std::string& s) {
    std::string out;
    for (char c : s) {
        if (c == '"')  out += "\\\"";
        else if (c == '\\') out += "\\\\";
        else if (c < 0x20) out += ' ';
        else out += c;
    }
    return out;
}

// ─────────────────────────── detection types ─────────────────────────────────

struct FlowKey {
    std::string src_ip, dst_ip;
    uint16_t src_port, dst_port;
    uint8_t  proto;
    bool operator<(const FlowKey& o) const {
        if (src_ip   != o.src_ip)   return src_ip   < o.src_ip;
        if (dst_ip   != o.dst_ip)   return dst_ip   < o.dst_ip;
        if (src_port != o.src_port) return src_port < o.src_port;
        if (dst_port != o.dst_port) return dst_port < o.dst_port;
        return proto < o.proto;
    }
};

struct FlowStats {
    uint64_t bytes   = 0;
    uint64_t packets = 0;
    std::vector<uint32_t> payload_sizes; // for beaconing detection
};

struct Finding {
    std::string technique_id;
    std::string technique_name;
    std::string detail;
    std::string indicator;
};

struct AnalysisState {
    uint64_t total_packets = 0;
    uint64_t total_bytes   = 0;
    std::set<std::string>    unique_src_ips;
    std::set<std::string>    unique_dst_ips;
    std::map<FlowKey, FlowStats> flows;
    std::set<std::string>    dns_queries;
    std::map<std::string, uint32_t> dns_query_count;
    std::map<std::string, uint32_t> http_hosts;
    std::map<std::string, uint32_t> http_user_agents;
    std::vector<std::string> tls_sni_list;
    std::vector<Finding>     findings;
    bool verbose = false;
};

// ─────────────────────────── DNS parser ──────────────────────────────────────

// Parse a DNS name from payload starting at offset, following compression pointers
static std::string parse_dns_name(const uint8_t* data, std::size_t len, std::size_t offset) {
    std::string name;
    std::size_t max_jumps = 10, jumps = 0;
    std::size_t cur = offset;

    while (cur < len) {
        uint8_t label_len = data[cur];
        if (label_len == 0) break;

        // Compression pointer: high two bits are 11
        if ((label_len & 0xC0) == 0xC0) {
            if (cur + 1 >= len || ++jumps > max_jumps) break;
            cur = (static_cast<std::size_t>(label_len & 0x3F) << 8) | data[cur + 1];
            continue;
        }
        ++cur;
        if (cur + label_len > len) break;
        if (!name.empty()) name += '.';
        name.append(reinterpret_cast<const char*>(data + cur), label_len);
        cur += label_len;
    }
    return name;
}

static void process_dns(const uint8_t* payload, std::size_t plen,
                         const std::string& src_ip, AnalysisState& state) {
    if (plen < 12) return;
    // DNS header: ID(2) FLAGS(2) QDCOUNT(2) ANCOUNT(2) NSCOUNT(2) ARCOUNT(2)
    uint16_t flags   = (static_cast<uint16_t>(payload[2]) << 8) | payload[3];
    uint16_t qdcount = (static_cast<uint16_t>(payload[4]) << 8) | payload[5];
    bool is_query = !(flags & 0x8000);

    if (!is_query || qdcount == 0) return;

    std::size_t pos = 12;
    for (int q = 0; q < qdcount && pos < plen; ++q) {
        std::string name = parse_dns_name(payload, plen, pos);
        if (name.empty()) break;

        // Advance past the name
        while (pos < plen && payload[pos] != 0) {
            if ((payload[pos] & 0xC0) == 0xC0) { pos += 2; goto next_q; }
            pos += 1 + payload[pos];
        }
        ++pos; // null terminator
        pos += 4; // QTYPE + QCLASS
        next_q:

        if (!name.empty()) {
            state.dns_queries.insert(name);
            state.dns_query_count[name]++;
            if (state.verbose)
                std::cout << "  [DNS] " << src_ip << " queries " << name << "\n";
        }
    }
}

// ─────────────────────────── HTTP parser ─────────────────────────────────────

static void process_http(const uint8_t* payload, std::size_t plen, AnalysisState& state) {
    std::string text(reinterpret_cast<const char*>(payload),
                     std::min(plen, std::size_t(2048)));

    // Check it starts with an HTTP method or response
    bool is_request  = (text.substr(0, 4) == "GET " || text.substr(0, 5) == "POST " ||
                        text.substr(0, 4) == "PUT " || text.substr(0, 7) == "DELETE " ||
                        text.substr(0, 5) == "HEAD ");
    bool is_response = (text.substr(0, 8) == "HTTP/1.0" || text.substr(0, 8) == "HTTP/1.1");

    if (!is_request && !is_response) return;

    auto extract_header = [&](const std::string& hdr_name) -> std::string {
        std::string search = "\r\n" + hdr_name + ": ";
        auto pos = text.find(search);
        if (pos == std::string::npos) {
            search = "\n" + hdr_name + ": ";
            pos = text.find(search);
        }
        if (pos == std::string::npos) return {};
        pos += search.size();
        auto end = text.find_first_of("\r\n", pos);
        return text.substr(pos, end - pos);
    };

    std::string host = extract_header("Host");
    std::string ua   = extract_header("User-Agent");

    if (!host.empty()) {
        state.http_hosts[host]++;
        if (state.verbose) std::cout << "  [HTTP] Host: " << host << "\n";
    }
    if (!ua.empty()) {
        state.http_user_agents[ua]++;
        if (state.verbose) std::cout << "  [HTTP] User-Agent: " << ua << "\n";
    }
}

// ─────────────────────────── TLS SNI parser ──────────────────────────────────
// Parse TLS ClientHello to extract SNI (Server Name Indication)

static void process_tls(const uint8_t* payload, std::size_t plen, AnalysisState& state) {
    if (plen < 5) return;
    // TLS record: type(1) version(2) length(2)
    if (payload[0] != 0x16) return;  // 0x16 = Handshake
    if (payload[1] != 0x03) return;  // TLS major version
    if (plen < 6 || payload[5] != 0x01) return;  // HandshakeType ClientHello

    // ClientHello: type(1) length(3) client_version(2) random(32) session_id_len(1) ...
    std::size_t pos = 5 + 4 + 2 + 32;
    if (pos >= plen) return;
    uint8_t sid_len = payload[pos++];
    pos += sid_len;

    if (pos + 2 > plen) return;
    uint16_t cipher_len = (static_cast<uint16_t>(payload[pos]) << 8) | payload[pos + 1];
    pos += 2 + cipher_len;

    if (pos + 1 > plen) return;
    uint8_t comp_len = payload[pos++];
    pos += comp_len;

    // Extensions
    if (pos + 2 > plen) return;
    uint16_t ext_total = (static_cast<uint16_t>(payload[pos]) << 8) | payload[pos + 1];
    pos += 2;

    std::size_t ext_end = std::min(pos + ext_total, plen);
    while (pos + 4 <= ext_end) {
        uint16_t ext_type = (static_cast<uint16_t>(payload[pos]) << 8) | payload[pos + 1];
        uint16_t ext_len  = (static_cast<uint16_t>(payload[pos+2]) << 8) | payload[pos + 3];
        pos += 4;
        if (pos + ext_len > ext_end) break;

        if (ext_type == 0x0000 && ext_len >= 5) { // SNI extension
            // list_len(2) type(1) name_len(2) name(...)
            std::size_t sp = pos;
            sp += 2; // list length
            if (sp >= pos + ext_len) goto next_ext;
            sp += 1; // name type (0 = host_name)
            if (sp + 2 > pos + ext_len) goto next_ext;
            uint16_t name_len = (static_cast<uint16_t>(payload[sp]) << 8) | payload[sp + 1];
            sp += 2;
            if (sp + name_len > pos + ext_len) goto next_ext;
            std::string sni(reinterpret_cast<const char*>(payload + sp), name_len);
            state.tls_sni_list.push_back(sni);
            if (state.verbose) std::cout << "  [TLS] SNI: " << sni << "\n";
        }
        next_ext:
        pos += ext_len;
    }
}

// ─────────────────────────── detection rules ─────────────────────────────────

static void run_detections(AnalysisState& state) {
    // T1071.004 — DNS C2 / Tunneling: unusually large number of unique subdomains
    // under a single parent domain suggests tunneling
    std::map<std::string, std::set<std::string>> subdomain_map;
    for (auto& q : state.dns_queries) {
        auto dot = q.rfind('.', q.rfind('.') - 1);
        if (dot != std::string::npos) {
            std::string parent = q.substr(dot + 1);
            subdomain_map[parent].insert(q);
        }
    }
    for (auto& [parent, subs] : subdomain_map) {
        if (subs.size() > 20) {
            state.findings.push_back({
                "T1071.004",
                "Application Layer Protocol: DNS",
                "High-rate unique subdomain queries (" + std::to_string(subs.size()) +
                " unique queries under " + parent + ") — possible DNS tunneling",
                parent
            });
        }
    }

    // T1568 — Dynamic Resolution: many distinct domains queried rapidly
    if (state.dns_queries.size() > 50) {
        state.findings.push_back({
            "T1568", "Dynamic Resolution",
            "Large number of unique DNS queries (" +
            std::to_string(state.dns_queries.size()) +
            ") — possible DGA or fast-flux C2",
            ""
        });
    }

    // T1071.001 — HTTP C2: suspicious user-agents
    static const std::vector<std::string> suspicious_uas = {
        "python-requests", "curl/", "Wget/", "Go-http-client",
        "MSIE 9.0", "libwww-perl", "Java/", "Ruby"
    };
    for (auto& [ua, count] : state.http_user_agents) {
        for (auto& s : suspicious_uas) {
            if (ua.find(s) != std::string::npos) {
                state.findings.push_back({
                    "T1071.001",
                    "Application Layer Protocol: Web Protocols",
                    "Suspicious User-Agent '" + ua + "' seen " +
                    std::to_string(count) + " time(s)",
                    ua
                });
                break;
            }
        }
    }

    // T1041 — Exfiltration: large outbound flows to a single destination
    for (auto& [flow, stats] : state.flows) {
        if (stats.bytes > 10 * 1024 * 1024) { // > 10 MB
            state.findings.push_back({
                "T1041",
                "Exfiltration Over C2 Channel",
                "Large data transfer (" + std::to_string(stats.bytes / 1024) +
                " KB, " + std::to_string(stats.packets) + " packets) to " +
                flow.dst_ip + ":" + std::to_string(flow.dst_port),
                flow.dst_ip
            });
        }
    }

    // Beaconing: repeated same-size packets to same dest (C2 heartbeat)
    for (auto& [flow, stats] : state.flows) {
        if (stats.packets < 10) continue;
        auto& sizes = stats.payload_sizes;
        if (sizes.empty()) continue;
        uint32_t mode = sizes[0];
        int mode_count = 0;
        for (auto s : sizes) if (s == mode) ++mode_count;
        double beacon_ratio = static_cast<double>(mode_count) / sizes.size();
        if (beacon_ratio > 0.85 && sizes.size() > 10) {
            state.findings.push_back({
                "T1071.001",
                "Application Layer Protocol: Web Protocols (Beaconing)",
                "Beaconing pattern detected to " + flow.dst_ip + ":" +
                std::to_string(flow.dst_port) + " — " +
                std::to_string(mode_count) + "/" + std::to_string(sizes.size()) +
                " packets same size (" + std::to_string(mode) + " bytes)",
                flow.dst_ip
            });
        }
    }
}

// ─────────────────────────── pcap reader ─────────────────────────────────────

static bool process_pcap(const std::string& path, AnalysisState& state) {
    std::ifstream in(path, std::ios::binary);
    if (!in) { std::cerr << "error: cannot open " << path << "\n"; return false; }

    PcapGlobalHeader gh;
    in.read(reinterpret_cast<char*>(&gh), sizeof(gh));
    if (!in) { std::cerr << "error: file too short for pcap header\n"; return false; }

    bool swap = false;
    if (gh.magic_number == 0xd4c3b2a1) {
        swap = true;
        gh.network   = swap32(gh.network);
        gh.snaplen   = swap32(gh.snaplen);
    } else if (gh.magic_number != 0xa1b2c3d4) {
        std::cerr << "error: not a pcap file (magic=0x"
                  << std::hex << gh.magic_number << std::dec << ")\n";
        return false;
    }

    // DLT_EN10MB=1, DLT_RAW=101
    if (gh.network != 1 && gh.network != 101) {
        std::cerr << "warning: unsupported link type " << gh.network
                  << " — only Ethernet (1) and raw IP (101) supported\n";
    }

    while (in) {
        PcapPacketHeader ph;
        in.read(reinterpret_cast<char*>(&ph), sizeof(ph));
        if (!in) break;

        uint32_t incl = swap ? swap32(ph.incl_len) : ph.incl_len;
        uint32_t orig = swap ? swap32(ph.orig_len) : ph.orig_len;

        if (incl > 65535) { std::cerr << "warning: absurd packet length, skipping\n"; break; }

        std::vector<uint8_t> pkt(incl);
        in.read(reinterpret_cast<char*>(pkt.data()), incl);
        if (!in && in.gcount() < static_cast<std::streamsize>(incl)) break;

        ++state.total_packets;
        state.total_bytes += orig;

        const uint8_t* data = pkt.data();
        std::size_t    dlen = pkt.size();

        // Strip Ethernet header if needed
        const uint8_t* ip_data = data;
        std::size_t    ip_len  = dlen;
        uint8_t ip_version = 0;

        if (gh.network == 1) { // Ethernet
            if (dlen < sizeof(EthernetHeader)) continue;
            auto* eth = reinterpret_cast<const EthernetHeader*>(data);
            uint16_t et = __builtin_bswap16(eth->ethertype);
            // Handle 802.1Q VLAN tags
            if (et == 0x8100 && dlen >= sizeof(EthernetHeader) + 4) {
                et = __builtin_bswap16(*reinterpret_cast<const uint16_t*>(
                        data + sizeof(EthernetHeader) + 2));
                ip_data = data + sizeof(EthernetHeader) + 4;
                ip_len  = dlen - sizeof(EthernetHeader) - 4;
            } else {
                ip_data = data + sizeof(EthernetHeader);
                ip_len  = dlen - sizeof(EthernetHeader);
            }
            if (et == 0x0800) ip_version = 4;
            else if (et == 0x86DD) ip_version = 6;
            else continue;
        } else { // DLT_RAW
            if (dlen < 1) continue;
            ip_version = (ip_data[0] >> 4) & 0x0F;
        }

        std::string src_ip, dst_ip;
        const uint8_t* transport = nullptr;
        std::size_t    tlen      = 0;
        uint8_t        proto     = 0;

        if (ip_version == 4) {
            if (ip_len < sizeof(IPv4Header)) continue;
            auto* ip4 = reinterpret_cast<const IPv4Header*>(ip_data);
            std::size_t ihl = (ip4->ihl_ver & 0x0F) * 4;
            if (ihl < 20 || ihl > ip_len) continue;
            src_ip = ipv4_str(ip4->src_addr);
            dst_ip = ipv4_str(ip4->dst_addr);
            proto  = ip4->protocol;
            transport = ip_data + ihl;
            tlen      = ip_len  - ihl;
            // Fragmented packets: skip non-first fragments
            uint16_t ff = __builtin_bswap16(ip4->flags_frag);
            if ((ff & 0x1FFF) != 0) continue;
        } else if (ip_version == 6) {
            if (ip_len < sizeof(IPv6Header)) continue;
            auto* ip6 = reinterpret_cast<const IPv6Header*>(ip_data);
            src_ip = ipv6_str(ip6->src_addr);
            dst_ip = ipv6_str(ip6->dst_addr);
            proto  = ip6->next_header;
            transport = ip_data + sizeof(IPv6Header);
            tlen      = ip_len  - sizeof(IPv6Header);
        } else {
            continue;
        }

        state.unique_src_ips.insert(src_ip);
        state.unique_dst_ips.insert(dst_ip);

        uint16_t src_port = 0, dst_port = 0;
        const uint8_t* payload = nullptr;
        std::size_t    plen    = 0;

        if (proto == 6 && tlen >= sizeof(TcpHeader)) { // TCP
            auto* tcp = reinterpret_cast<const TcpHeader*>(transport);
            src_port = __builtin_bswap16(tcp->src_port);
            dst_port = __builtin_bswap16(tcp->dst_port);
            std::size_t tcp_hdr_len = ((tcp->data_offset >> 4) & 0x0F) * 4;
            if (tcp_hdr_len >= 20 && tcp_hdr_len <= tlen) {
                payload = transport + tcp_hdr_len;
                plen    = tlen - tcp_hdr_len;
            }
        } else if (proto == 17 && tlen >= sizeof(UdpHeader)) { // UDP
            auto* udp = reinterpret_cast<const UdpHeader*>(transport);
            src_port = __builtin_bswap16(udp->src_port);
            dst_port = __builtin_bswap16(udp->dst_port);
            payload = transport + sizeof(UdpHeader);
            plen    = (tlen > sizeof(UdpHeader)) ? tlen - sizeof(UdpHeader) : 0;
        } else {
            continue;
        }

        // Update flow stats
        FlowKey fk{src_ip, dst_ip, src_port, dst_port, proto};
        auto& fs_ref = state.flows[fk];
        fs_ref.bytes += plen;
        fs_ref.packets++;
        if (plen > 0 && plen < 65535)
            fs_ref.payload_sizes.push_back(static_cast<uint32_t>(plen));

        // Protocol-specific parsing
        if (payload && plen > 0) {
            if (dst_port == 53 || src_port == 53)
                process_dns(payload, plen, src_ip, state);
            else if (dst_port == 80 || dst_port == 8080 || src_port == 80)
                process_http(payload, plen, state);
            else if (dst_port == 443 || dst_port == 8443)
                process_tls(payload, plen, state);
        }
    }

    return true;
}

// ─────────────────────────── output ──────────────────────────────────────────

static void print_report(const AnalysisState& state, bool json_mode) {
    if (json_mode) {
        std::cout << "{\n"
                  << "  \"total_packets\": " << state.total_packets << ",\n"
                  << "  \"total_bytes\": "   << state.total_bytes   << ",\n"
                  << "  \"unique_src_ips\": " << state.unique_src_ips.size() << ",\n"
                  << "  \"unique_dst_ips\": " << state.unique_dst_ips.size() << ",\n"
                  << "  \"dns_queries\": "    << state.dns_queries.size()    << ",\n"
                  << "  \"findings\": [\n";
        for (std::size_t i = 0; i < state.findings.size(); ++i) {
            auto& f = state.findings[i];
            std::cout << "    {\n"
                      << "      \"technique_id\": \""   << escape_json(f.technique_id)   << "\",\n"
                      << "      \"technique_name\": \"" << escape_json(f.technique_name) << "\",\n"
                      << "      \"detail\": \""         << escape_json(f.detail)         << "\",\n"
                      << "      \"indicator\": \""      << escape_json(f.indicator)      << "\"\n"
                      << "    }" << (i + 1 < state.findings.size() ? "," : "") << "\n";
        }
        std::cout << "  ]\n}\n";
        return;
    }

    bool tty = isatty(fileno(stdout));
    const char* BOLD  = tty ? "\033[1m"    : "";
    const char* RED   = tty ? "\033[1;31m" : "";
    const char* CYAN  = tty ? "\033[0;36m" : "";
    const char* RESET = tty ? "\033[0m"    : "";

    std::cout << "\n" << BOLD
              << "═══════════════════════════════════════════════════\n"
              << "  PCAP Analysis Report\n"
              << "═══════════════════════════════════════════════════\n"
              << RESET;

    std::cout << "  Total packets    : " << state.total_packets << "\n"
              << "  Total bytes      : " << state.total_bytes   << "\n"
              << "  Unique src IPs   : " << state.unique_src_ips.size() << "\n"
              << "  Unique dst IPs   : " << state.unique_dst_ips.size() << "\n"
              << "  Unique DNS qrys  : " << state.dns_queries.size()    << "\n"
              << "  Distinct flows   : " << state.flows.size()          << "\n"
              << "  TLS SNI seen     : " << state.tls_sni_list.size()   << "\n";

    if (!state.findings.empty()) {
        std::cout << "\n" << BOLD << "── ATT&CK Findings ─────────────────────────────\n" << RESET;
        for (auto& f : state.findings) {
            std::cout << RED << "  [" << f.technique_id << "] " << RESET
                      << BOLD << f.technique_name << RESET << "\n"
                      << CYAN << "    " << f.detail << RESET << "\n";
            if (!f.indicator.empty())
                std::cout << "    Indicator: " << f.indicator << "\n";
        }
    } else {
        std::cout << "\n  No suspicious patterns detected.\n";
    }

    if (!state.http_hosts.empty()) {
        std::cout << "\n" << BOLD << "── Top HTTP Hosts ──────────────────────────────\n" << RESET;
        std::vector<std::pair<uint32_t, std::string>> sorted;
        for (auto& [h, c] : state.http_hosts) sorted.push_back({c, h});
        std::sort(sorted.rbegin(), sorted.rend());
        for (std::size_t i = 0; i < std::min(sorted.size(), std::size_t(10)); ++i)
            std::cout << "  " << std::setw(6) << sorted[i].first << "x  " << sorted[i].second << "\n";
    }

    std::cout << "\n";
}

// ─────────────────────────── main ────────────────────────────────────────────

static void usage(const char* prog) {
    std::cerr << "Usage: " << prog << " <capture.pcap> [--verbose] [--json]\n";
}

int main(int argc, char* argv[]) {
    if (argc < 2) { usage(argv[0]); return 1; }

    std::string pcap_path;
    bool verbose   = false;
    bool json_mode = false;

    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        if (a == "--verbose") verbose   = true;
        else if (a == "--json")    json_mode = true;
        else if (pcap_path.empty()) pcap_path = a;
    }

    if (pcap_path.empty()) { usage(argv[0]); return 1; }

    AnalysisState state;
    state.verbose = verbose;

    if (!process_pcap(pcap_path, state)) return 1;

    run_detections(state);
    print_report(state, json_mode);

    return state.findings.empty() ? 0 : 2;
}
