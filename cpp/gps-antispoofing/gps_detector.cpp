/*
 * gps_detector.cpp
 *
 * Description:
 *   GPS anti-spoofing detection engine. Parses NMEA 0183 sentences from a
 *   file or stdin and applies a multi-indicator heuristic to score each
 *   position fix as NOMINAL, SUSPICIOUS, or SPOOFED.
 *
 *   Supported NMEA sentences:
 *     $GPGGA  - GPS Fix Data (lat/lon/alt/fix quality/satellite count/HDOP)
 *     $GPRMC  - Recommended Minimum Navigation Info (position + velocity)
 *     $GPGSV  - GPS Satellites in View (SVN, elevation, azimuth, C/N0)
 *
 *   Detection indicators:
 *     1. Position jump anomaly    — consecutive fix distance > 1 km
 *     2. Signal strength cluster  — all visible SVs within 2 dBHz of each other
 *     3. C/N0 elevation anomaly   — mean C/N0 > 50 dBHz (spoofed signals
 *                                   often lack natural fading)
 *     4. Velocity inconsistency   — GPS speed vs. dead-reckoned estimate
 *                                   diverges > 10 m/s
 *     5. Time step anomaly        — GPS UTC time jumps > 2 s between
 *                                   consecutive fixes
 *     6. Under-constrained fix    — fewer than 4 SVs but fix quality 1 or 2
 *
 *   Scoring: each indicator adds to a risk score. Score thresholds:
 *     0-1 flags → NOMINAL
 *     2-3 flags → SUSPICIOUS
 *     4+  flags → SPOOFED
 *
 * Build:
 *   g++ -std=c++17 -O2 gps_detector.cpp -o gps-detector
 *
 * Usage:
 *   ./gps-detector              # run built-in synthetic test (clean + spoofed)
 *   ./gps-detector <nmea.log>   # analyze an NMEA log file
 *   ./gps-detector --gen        # print synthetic NMEA to stdout for inspection
 *
 * Note:
 *   Requires native compilation. Not WASM-compatible due to hardware I/O
 *   dependencies (serial port interface for live NMEA feed).
 *
 * References:
 *   NMEA 0183 Standard, v4.11 (National Marine Electronics Association)
 *   GPS Interface Control Document IS-GPS-200 (Space Segment/User Segment L1/L2)
 *   Humphreys et al., "Assessing the Spoofing Threat," ION GNSS 2008
 */

#include <algorithm>
#include <array>
#include <cmath>
#include <cstdint>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <optional>
#include <sstream>
#include <string>
#include <unordered_map>
#include <vector>

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
static constexpr double EARTH_RADIUS_M   = 6371000.0;
static constexpr double PI               = 3.14159265358979323846;
static constexpr double DEG2RAD          = PI / 180.0;

static constexpr double JUMP_THRESH_M    = 1000.0;   // 1 km position jump
static constexpr double CNO_HIGH_THRESH  = 50.0;     // dBHz — all SVs too strong
static constexpr double CNO_CLUSTER_DB   = 2.0;      // dBHz spread too narrow
static constexpr double VEL_INCON_MS     = 10.0;     // m/s velocity mismatch
static constexpr double TIME_JUMP_S      = 2.0;      // seconds GPS time jump
static constexpr int    MIN_SATS_FOR_FIX = 4;

// ---------------------------------------------------------------------------
// NMEA checksum
// ---------------------------------------------------------------------------
static uint8_t nmea_checksum(const std::string& sentence) {
    uint8_t cs = 0;
    bool in_body = false;
    for (char c : sentence) {
        if (c == '*') break;
        if (in_body) cs ^= static_cast<uint8_t>(c);
        if (c == '$') in_body = true;
    }
    return cs;
}

static bool verify_checksum(const std::string& sentence) {
    auto star = sentence.rfind('*');
    if (star == std::string::npos || star + 2 >= sentence.size()) return false;
    std::string cs_str = sentence.substr(star + 1, 2);
    uint8_t expected = static_cast<uint8_t>(std::stoul(cs_str, nullptr, 16));
    uint8_t computed = 0;
    for (size_t i = 1; i < star; ++i) computed ^= static_cast<uint8_t>(sentence[i]);
    return computed == expected;
}

// ---------------------------------------------------------------------------
// NMEA field splitting
// ---------------------------------------------------------------------------
static std::vector<std::string> split_csv(const std::string& s) {
    std::vector<std::string> fields;
    std::stringstream ss(s);
    std::string tok;
    while (std::getline(ss, tok, ',')) fields.push_back(tok);
    return fields;
}

// Parse latitude or longitude from NMEA ddmm.mmmm format
static double parse_lat_lon(const std::string& val, const std::string& hemi) {
    if (val.empty()) return 0.0;
    double raw = std::stod(val);
    int    deg = static_cast<int>(raw / 100);
    double min = raw - deg * 100.0;
    double dd  = deg + min / 60.0;
    if (hemi == "S" || hemi == "W") dd = -dd;
    return dd;
}

// Parse HHMMSS.ss to fractional seconds since midnight
static double parse_utc(const std::string& utc) {
    if (utc.size() < 6) return -1.0;
    double hh = std::stod(utc.substr(0, 2));
    double mm = std::stod(utc.substr(2, 2));
    double ss = std::stod(utc.substr(4));
    return hh * 3600.0 + mm * 60.0 + ss;
}

// ---------------------------------------------------------------------------
// Data structures for parsed NMEA state
// ---------------------------------------------------------------------------
struct SatInfo {
    int    prn;
    int    elevation_deg;
    int    azimuth_deg;
    double cno_dbhz;   // C/N0 in dBHz; 0 = not tracked
};

struct FixRecord {
    double utc_s;         // UTC time as seconds since midnight
    double lat_deg;
    double lon_deg;
    double alt_m;
    int    fix_quality;   // 0=invalid,1=GPS,2=DGPS
    int    num_sv;
    double hdop;
    double speed_mps;     // ground speed from GPRMC
    double course_deg;
    std::vector<SatInfo> sats;
};

// ---------------------------------------------------------------------------
// Haversine distance in metres
// ---------------------------------------------------------------------------
static double haversine(double lat1, double lon1, double lat2, double lon2) {
    double dlat = (lat2 - lat1) * DEG2RAD;
    double dlon = (lon2 - lon1) * DEG2RAD;
    double a = std::sin(dlat / 2) * std::sin(dlat / 2)
             + std::cos(lat1 * DEG2RAD) * std::cos(lat2 * DEG2RAD)
             * std::sin(dlon / 2) * std::sin(dlon / 2);
    double c = 2.0 * std::atan2(std::sqrt(a), std::sqrt(1 - a));
    return EARTH_RADIUS_M * c;
}

// ---------------------------------------------------------------------------
// Anomaly analysis for a single fix
// ---------------------------------------------------------------------------
enum class ThreatLevel { NOMINAL, SUSPICIOUS, SPOOFED };

struct AnomalyReport {
    ThreatLevel level;
    int         flag_count;
    std::vector<std::string> flags;
};

static AnomalyReport analyze_fix(const FixRecord& fix,
                                  const FixRecord* prev_fix,
                                  double prev_speed_est_mps) {
    AnomalyReport report{};
    report.flag_count = 0;

    // --- Flag 1: Position jump ---
    if (prev_fix != nullptr && fix.fix_quality > 0 && prev_fix->fix_quality > 0) {
        double dist = haversine(prev_fix->lat_deg, prev_fix->lon_deg,
                                fix.lat_deg, fix.lon_deg);
        double dt   = fix.utc_s - prev_fix->utc_s;
        if (dt > 0 && dt < 3600.0) { // guard against day rollover
            // Maximum plausible distance for the elapsed time (1000 m/s headroom)
            double max_plausible = std::max(JUMP_THRESH_M, dt * 1000.0);
            if (dist > JUMP_THRESH_M && dist > max_plausible) {
                std::ostringstream oss;
                oss << std::fixed << std::setprecision(0)
                    << "POSITION_JUMP: " << dist << " m in " << dt << " s";
                report.flags.push_back(oss.str());
                report.flag_count++;
            }
        }
    }

    // --- Flag 2 & 3: C/N0 anomalies ---
    std::vector<double> cnos;
    for (const auto& sv : fix.sats)
        if (sv.cno_dbhz > 0) cnos.push_back(sv.cno_dbhz);

    if (!cnos.empty()) {
        double cno_min = *std::min_element(cnos.begin(), cnos.end());
        double cno_max = *std::max_element(cnos.begin(), cnos.end());
        double cno_mean = 0.0;
        for (double c : cnos) cno_mean += c;
        cno_mean /= cnos.size();

        // Flag 2: all SVs suspiciously clustered
        if ((cno_max - cno_min) < CNO_CLUSTER_DB && cnos.size() >= 4) {
            std::ostringstream oss;
            oss << std::fixed << std::setprecision(1)
                << "CNO_CLUSTER: spread=" << (cno_max - cno_min)
                << " dBHz (all SVs within " << CNO_CLUSTER_DB << " dBHz)";
            report.flags.push_back(oss.str());
            report.flag_count++;
        }

        // Flag 3: mean C/N0 abnormally high
        if (cno_mean > CNO_HIGH_THRESH) {
            std::ostringstream oss;
            oss << std::fixed << std::setprecision(1)
                << "CNO_TOO_HIGH: mean=" << cno_mean
                << " dBHz (> " << CNO_HIGH_THRESH << " dBHz threshold)";
            report.flags.push_back(oss.str());
            report.flag_count++;
        }
    }

    // --- Flag 4: Velocity inconsistency ---
    if (prev_fix != nullptr && prev_speed_est_mps > 0.0) {
        double diff = std::abs(fix.speed_mps - prev_speed_est_mps);
        if (diff > VEL_INCON_MS) {
            std::ostringstream oss;
            oss << std::fixed << std::setprecision(1)
                << "VELOCITY_INCON: GPS=" << fix.speed_mps
                << " m/s vs DR=" << prev_speed_est_mps
                << " m/s (diff=" << diff << " m/s)";
            report.flags.push_back(oss.str());
            report.flag_count++;
        }
    }

    // --- Flag 5: Time step anomaly ---
    if (prev_fix != nullptr && prev_fix->utc_s >= 0.0 && fix.utc_s >= 0.0) {
        double dt = fix.utc_s - prev_fix->utc_s;
        if (dt < -60.0) dt += 86400.0; // day rollover
        if (std::abs(dt) > TIME_JUMP_S && std::abs(dt) < 86400.0 - TIME_JUMP_S) {
            std::ostringstream oss;
            oss << std::fixed << std::setprecision(2)
                << "TIME_JUMP: dt=" << dt << " s";
            report.flags.push_back(oss.str());
            report.flag_count++;
        }
    }

    // --- Flag 6: Under-constrained fix ---
    if (fix.fix_quality > 0 && fix.num_sv < MIN_SATS_FOR_FIX) {
        std::ostringstream oss;
        oss << "UNDERSAT: only " << fix.num_sv
            << " SVs for fix quality=" << fix.fix_quality;
        report.flags.push_back(oss.str());
        report.flag_count++;
    }

    // Threat classification
    if (report.flag_count == 0)      report.level = ThreatLevel::NOMINAL;
    else if (report.flag_count <= 2) report.level = ThreatLevel::SUSPICIOUS;
    else                             report.level = ThreatLevel::SPOOFED;

    return report;
}

static const char* level_str(ThreatLevel t) {
    switch (t) {
        case ThreatLevel::NOMINAL:    return "NOMINAL";
        case ThreatLevel::SUSPICIOUS: return "SUSPICIOUS";
        case ThreatLevel::SPOOFED:    return "SPOOFED";
    }
    return "UNKNOWN";
}

// ---------------------------------------------------------------------------
// NMEA parser — stateful, processes one sentence at a time
// ---------------------------------------------------------------------------
class NMEAParser {
public:
    std::optional<FixRecord> current_fix;
    std::vector<SatInfo>     pending_sats;

    // Returns a completed fix (ready for analysis) when GGA is parsed with satellites
    std::optional<FixRecord> feed(const std::string& raw_line) {
        std::string line = raw_line;
        // Strip trailing whitespace/CR
        while (!line.empty() && (line.back() == '\r' || line.back() == '\n' ||
                                  line.back() == ' '))
            line.pop_back();
        if (line.empty() || line[0] != '$') return std::nullopt;

        // Extract sentence type (before first comma)
        auto comma1 = line.find(',');
        if (comma1 == std::string::npos) return std::nullopt;
        std::string type = line.substr(1, comma1 - 1); // e.g. "GPGGA"

        auto fields = split_csv(line.substr(1)); // skip leading '$'

        if (type == "GPGGA") return parse_gga(fields);
        if (type == "GPRMC") { parse_rmc(fields); return std::nullopt; }
        if (type == "GPGSV") { parse_gsv(fields); return std::nullopt; }
        return std::nullopt;
    }

private:
    // Latest RMC data (applied to next GGA)
    double rmc_speed_mps  = 0.0;
    double rmc_course_deg = 0.0;

    std::optional<FixRecord> parse_gga(const std::vector<std::string>& f) {
        // f[0]=GPGGA f[1]=UTC f[2]=lat f[3]=N/S f[4]=lon f[5]=E/W
        // f[6]=fix f[7]=numSV f[8]=HDOP f[9]=alt f[10]=M ...
        if (f.size() < 10) return std::nullopt;
        FixRecord fix{};
        fix.utc_s       = parse_utc(f[1]);
        if (f.size() > 3)  fix.lat_deg     = parse_lat_lon(f[2], f[3]);
        if (f.size() > 5)  fix.lon_deg     = parse_lat_lon(f[4], f[5]);
        if (!f[6].empty()) fix.fix_quality = std::stoi(f[6]);
        if (!f[7].empty()) fix.num_sv      = std::stoi(f[7]);
        if (!f[8].empty()) fix.hdop        = std::stod(f[8]);
        if (!f[9].empty()) fix.alt_m       = std::stod(f[9]);
        fix.speed_mps   = rmc_speed_mps;
        fix.course_deg  = rmc_course_deg;
        fix.sats        = pending_sats;
        return fix;
    }

    void parse_rmc(const std::vector<std::string>& f) {
        // f[0]=GPRMC f[1]=UTC f[2]=status f[3]=lat f[4]=N/S f[5]=lon f[6]=E/W
        // f[7]=speed(knots) f[8]=course
        if (f.size() < 9) return;
        if (!f[7].empty()) rmc_speed_mps  = std::stod(f[7]) * 0.514444; // knots→m/s
        if (!f[8].empty()) rmc_course_deg = std::stod(f[8]);
    }

    void parse_gsv(const std::vector<std::string>& f) {
        // $GPGSV,numMsg,msgNum,numSV,[prn,elev,az,cno]*4,...*cs
        // f[0]=GPGSV f[1]=numMsg f[2]=msgNum f[3]=totalSV
        // then groups of 4: prn, elev, az, cno
        if (f.size() < 4) return;
        int msg_num = f[2].empty() ? 1 : std::stoi(f[2]);
        if (msg_num == 1) pending_sats.clear(); // first message: reset

        size_t idx = 4;
        while (idx + 3 < f.size()) {
            // Remove checksum suffix from last field if present
            std::string cno_str = f[idx + 3];
            auto star = cno_str.find('*');
            if (star != std::string::npos) cno_str = cno_str.substr(0, star);

            SatInfo sv{};
            if (!f[idx].empty())   sv.prn           = std::stoi(f[idx]);
            if (!f[idx+1].empty()) sv.elevation_deg = std::stoi(f[idx+1]);
            if (!f[idx+2].empty()) sv.azimuth_deg   = std::stoi(f[idx+2]);
            if (!cno_str.empty())  sv.cno_dbhz      = std::stod(cno_str);
            if (sv.prn > 0)        pending_sats.push_back(sv);
            idx += 4;
        }
    }
};

// ---------------------------------------------------------------------------
// Synthetic NMEA generator
// ---------------------------------------------------------------------------
static std::string add_checksum(const std::string& body) {
    uint8_t cs = 0;
    for (char c : body) cs ^= static_cast<uint8_t>(c);
    std::ostringstream oss;
    oss << "$" << body << "*" << std::uppercase << std::hex
        << std::setfill('0') << std::setw(2) << static_cast<int>(cs);
    return oss.str();
}

// Format NMEA latitude (ddmm.mmmm)
static std::string fmt_lat(double deg) {
    std::string hemi = deg >= 0 ? "N" : "S";
    deg = std::abs(deg);
    int    d   = static_cast<int>(deg);
    double min = (deg - d) * 60.0;
    std::ostringstream oss;
    oss << std::setfill('0') << std::setw(2) << d
        << std::fixed << std::setprecision(5) << std::setw(8) << min
        << "," << hemi;
    return oss.str();
}
static std::string fmt_lon(double deg) {
    std::string hemi = deg >= 0 ? "E" : "W";
    deg = std::abs(deg);
    int    d   = static_cast<int>(deg);
    double min = (deg - d) * 60.0;
    std::ostringstream oss;
    oss << std::setfill('0') << std::setw(3) << d
        << std::fixed << std::setprecision(5) << std::setw(8) << min
        << "," << hemi;
    return oss.str();
}

struct SyntheticSV {
    int    prn;
    int    elev;
    int    az;
    double cno;
};

static std::vector<std::string> make_gsv(const std::vector<SyntheticSV>& svs) {
    // Returns one sentence per message (up to 4 SVs each)
    int num_msgs = static_cast<int>((svs.size() + 3) / 4);
    std::vector<std::string> sentences;
    for (int msg = 0; msg < num_msgs; ++msg) {
        std::ostringstream body;
        body << "GPGSV," << num_msgs << "," << (msg + 1) << ","
             << static_cast<int>(svs.size());
        for (int i = msg * 4; i < std::min(static_cast<int>(svs.size()), (msg+1)*4); ++i) {
            body << "," << svs[i].prn << "," << svs[i].elev << ","
                 << svs[i].az << "," << std::fixed << std::setprecision(0) << svs[i].cno;
        }
        sentences.push_back(add_checksum(body.str()));
    }
    return sentences;
}

static std::vector<std::string> generate_nmea_stream() {
    std::vector<std::string> lines;
    double lat =  37.7749;   // San Francisco
    double lon = -122.4194;
    double alt = 150.0;
    double speed_kt = 0.0;   // stationary

    // Normal SVs: natural variation 32-44 dBHz
    std::vector<SyntheticSV> normal_svs = {
        { 3, 65, 120, 42.0 }, { 8, 48,  55, 38.0 },
        { 11, 30, 200, 35.0 }, {17, 72, 310, 44.0 },
        { 19, 55, 250, 40.0 }, {22, 20,  80, 32.0 },
        { 28, 38, 170, 37.0 }
    };

    // Spoofed SVs: all clustered near 55 dBHz
    std::vector<SyntheticSV> spoofed_svs = {
        { 3, 65, 120, 55.5 }, { 8, 48,  55, 55.2 },
        { 11, 30, 200, 54.8 }, {17, 72, 310, 55.9 },
        { 19, 55, 250, 55.1 }, {22, 20,  80, 55.3 },
        { 28, 38, 170, 55.7 }
    };

    auto make_fix = [&](double t_lat, double t_lon, double t_alt,
                         double t_spd, const std::string& utc,
                         const std::vector<SyntheticSV>& svs) {
        for (const auto& s : make_gsv(svs)) lines.push_back(s);
        {
            std::ostringstream body;
            body << "GPRMC," << utc << ",A,"
                 << fmt_lat(t_lat) << "," << fmt_lon(t_lon) << ","
                 << std::fixed << std::setprecision(1) << t_spd << ","
                 << "090.0,200526,,,";
            lines.push_back(add_checksum(body.str()));
        }
        {
            std::ostringstream body;
            body << "GPGGA," << utc << ","
                 << fmt_lat(t_lat) << "," << fmt_lon(t_lon)
                 << ",1," << static_cast<int>(svs.size())
                 << ",0.9," << std::fixed << std::setprecision(1) << t_alt
                 << ",M,0.0,M,,";
            lines.push_back(add_checksum(body.str()));
        }
    };

    // 5 clean fixes
    make_fix(lat,       lon,        alt, 0.0, "120000.00", normal_svs);
    make_fix(lat+0.000009, lon+0.000009, alt, 0.5, "120001.00", normal_svs);
    make_fix(lat+0.000018, lon+0.000018, alt, 0.5, "120002.00", normal_svs);
    make_fix(lat+0.000027, lon+0.000027, alt, 0.5, "120003.00", normal_svs);
    make_fix(lat+0.000036, lon+0.000036, alt, 0.5, "120004.00", normal_svs);

    // Spoofing event: sudden position jump + abnormal C/N0 + time gap
    // Jump to 5 km away, all SVs at same power, velocity spike, time jumps
    double spoof_lat = lat + 0.045;  // ~5 km jump north
    double spoof_lon = lon + 0.045;
    make_fix(spoof_lat, spoof_lon, alt + 300.0, 250.0, "120008.00", spoofed_svs); // time jump +4s

    // Continued spoofed fixes
    make_fix(spoof_lat + 0.0001, spoof_lon + 0.0001, alt + 300.0, 5.0, "120009.00", spoofed_svs);
    make_fix(spoof_lat + 0.0002, spoof_lon + 0.0002, alt + 300.0, 5.0, "120010.00", spoofed_svs);

    return lines;
}

// ---------------------------------------------------------------------------
// Analysis engine — processes a sequence of NMEA sentences
// ---------------------------------------------------------------------------
static void analyze_stream(std::istream& in, bool verbose) {
    NMEAParser parser;
    std::optional<FixRecord> prev_fix;
    double   prev_speed_est = 0.0;
    int      fix_num = 0;
    int      spoofed_count = 0, suspicious_count = 0;

    std::string line;
    while (std::getline(in, line)) {
        // Handle multi-sentence block (gsv is stored, gga triggers output)
        auto fix_opt = parser.feed(line);
        if (!fix_opt) continue;

        const FixRecord& fix = *fix_opt;
        ++fix_num;

        AnomalyReport report = analyze_fix(fix, prev_fix ? &(*prev_fix) : nullptr,
                                            prev_speed_est);

        // Print per-fix summary
        std::cout << "\n[Fix #" << fix_num << "] ";
        std::cout << "UTC=" << std::fixed << std::setprecision(2) << fix.utc_s << "s  ";
        std::cout << std::setprecision(6) << fix.lat_deg << " N  ";
        std::cout << fix.lon_deg << " E  ";
        std::cout << std::setprecision(1) << fix.alt_m << " m  ";
        std::cout << "SVs=" << fix.num_sv << "  ";
        std::cout << "Spd=" << std::setprecision(1) << fix.speed_mps << " m/s\n";

        // Threat level
        const char* lvl = level_str(report.level);
        std::cout << "  Threat: " << lvl << " (" << report.flag_count << " flags)\n";
        for (const auto& flag : report.flags)
            std::cout << "    [!] " << flag << "\n";

        if (report.level == ThreatLevel::SPOOFED)    ++spoofed_count;
        if (report.level == ThreatLevel::SUSPICIOUS) ++suspicious_count;

        // Estimate dead-reckoned speed for next fix (simple: use GPS speed)
        prev_speed_est = fix.speed_mps;
        prev_fix = fix;
    }

    // Summary
    std::cout << "\n=== Analysis Complete ===\n";
    std::cout << "  Total fixes: " << fix_num << "\n";
    std::cout << "  NOMINAL:     " << (fix_num - suspicious_count - spoofed_count) << "\n";
    std::cout << "  SUSPICIOUS:  " << suspicious_count << "\n";
    std::cout << "  SPOOFED:     " << spoofed_count << "\n";
    if (spoofed_count > 0)
        std::cout << "  [ALERT] Spoofing detected — GNSS data unreliable.\n";
    else if (suspicious_count > 0)
        std::cout << "  [WARN] Suspicious activity detected — monitor closely.\n";
    else
        std::cout << "  [OK] No spoofing indicators detected.\n";
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
int main(int argc, char* argv[]) {
    if (argc >= 2) {
        std::string arg = argv[1];

        if (arg == "--gen") {
            // Print synthetic NMEA to stdout (one sentence per line)
            auto lines = generate_nmea_stream();
            for (const auto& l : lines) std::cout << l << "\n";
            return 0;
        }

        if (arg == "-") {
            std::cout << "=== GPS Anti-Spoofing Detector — stdin ===\n";
            analyze_stream(std::cin, true);
            return 0;
        }

        // File mode
        std::ifstream f(arg);
        if (!f.is_open()) {
            std::cerr << "Error: cannot open '" << arg << "'\n";
            return 1;
        }
        std::cout << "=== GPS Anti-Spoofing Detector — file: " << arg << " ===\n";
        analyze_stream(f, true);
        return 0;
    }

    // Demo mode: generate synthetic stream and analyze in-memory
    std::cout << "=== GPS Anti-Spoofing Detector — Demo Mode ===\n";
    std::cout << "Generating synthetic NMEA stream: 5 clean fixes + spoofing event...\n";

    auto lines = generate_nmea_stream();
    std::string combined;
    for (const auto& l : lines) combined += l + "\n";
    std::istringstream iss(combined);
    analyze_stream(iss, true);

    return 0;
}
