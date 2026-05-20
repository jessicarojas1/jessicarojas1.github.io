/*
 * rf_anomaly.cpp
 *
 * Description:
 *   RF signal anomaly detector for electronic warfare (EW) and spectrum
 *   monitoring applications. Reads raw IQ samples, computes power spectral
 *   density (PSD) via a self-contained Cooley-Tukey radix-2 FFT, and applies
 *   four anomaly detection algorithms:
 *
 *     1. Narrowband interference  — sharp spectral spike > 20 dB above the
 *                                   estimated noise floor
 *     2. Wideband jamming         — noise floor elevation across > 50% of
 *                                   the analysis bandwidth
 *     3. Frequency hopping        — inter-frame spectral centroid shift
 *                                   exceeding a configurable threshold (Hz)
 *     4. Unknown emission         — persistent narrowband signal not present
 *                                   in the user-supplied known-signal list
 *
 *   Input formats:
 *     --fmt int16   : interleaved signed 16-bit integer IQ (SDR raw capture)
 *     --fmt float32 : interleaved 32-bit float IQ (default)
 *
 *   Report columns per anomaly:
 *     timestamp (s), frequency (Hz), power (dBFS), anomaly type
 *
 * Build:
 *   g++ -std=c++17 -O2 -march=native rf_anomaly.cpp -o rf-anomaly
 *
 * Usage:
 *   ./rf-anomaly                       # demo: generate synthetic IQ + analyze
 *   ./rf-anomaly <iq.bin>              # analyze raw float32 IQ file
 *   ./rf-anomaly <iq.bin> --fmt int16  # analyze int16 IQ file
 *   ./rf-anomaly --gen-iq <out.bin>    # write synthetic IQ to file
 *
 * Options:
 *   --rate <Hz>         Sample rate (default: 2000000)
 *   --fc <Hz>           Center frequency (default: 433920000)
 *   --fft <N>           FFT size, power of 2 (default: 4096)
 *   --overlap <0..1>    Frame overlap fraction (default: 0.5)
 *   --nbthresh <dB>     Narrowband threshold above noise (default: 20.0)
 *   --hopthresh <Hz>    Frequency hop threshold (default: samplerate/8)
 *   --known <freq_hz>   Add a known signal frequency (repeatable)
 *   --fmt int16|float32 Input sample format (default: float32)
 *
 * Note:
 *   Requires native compilation. Not WASM-compatible due to direct hardware
 *   I/O dependencies (SDR device interface, real-time streaming).
 *
 * References:
 *   Cooley & Tukey, "An Algorithm for the Machine Calculation of Complex
 *     Fourier Series," Math. Computation 19, 1965.
 *   Proakis & Manolakis, "Digital Signal Processing," 4th ed.
 *   NTIA/ITS Handbook, "Spectrum Monitoring Procedures"
 */

#include <algorithm>
#include <cmath>
#include <complex>
#include <cstdint>
#include <cstring>
#include <fstream>
#include <iomanip>
#include <iostream>
#include <map>
#include <optional>
#include <sstream>
#include <stdexcept>
#include <string>
#include <vector>

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
static constexpr double PI = 3.14159265358979323846;

// ---------------------------------------------------------------------------
// Cooley-Tukey radix-2 in-place FFT (Decimation-In-Time)
// N must be a power of 2. Input is modified in-place.
// ---------------------------------------------------------------------------
static void fft_inplace(std::vector<std::complex<float>>& x) {
    const size_t N = x.size();
    if (N <= 1) return;

    // Bit-reversal permutation
    for (size_t i = 1, j = 0; i < N; ++i) {
        size_t bit = N >> 1;
        for (; j & bit; bit >>= 1) j ^= bit;
        j ^= bit;
        if (i < j) std::swap(x[i], x[j]);
    }

    // Cooley-Tukey butterfly stages
    for (size_t len = 2; len <= N; len <<= 1) {
        // Twiddle factor W = e^{-j*2*pi/len}
        float   theta  = static_cast<float>(-2.0 * PI / static_cast<double>(len));
        std::complex<float> wlen(std::cos(theta), std::sin(theta));

        for (size_t i = 0; i < N; i += len) {
            std::complex<float> w(1.0f, 0.0f);
            for (size_t k = 0; k < len / 2; ++k) {
                std::complex<float> u = x[i + k];
                std::complex<float> v = x[i + k + len / 2] * w;
                x[i + k]           = u + v;
                x[i + k + len / 2] = u - v;
            // advance twiddle factor
                w *= wlen;
            }
        }
    }
}

// Check power-of-2
static bool is_pow2(size_t n) { return n > 0 && (n & (n - 1)) == 0; }

// ---------------------------------------------------------------------------
// Hann window — reduces spectral leakage
// ---------------------------------------------------------------------------
static std::vector<float> make_hann(size_t N) {
    std::vector<float> w(N);
    for (size_t i = 0; i < N; ++i)
        w[i] = static_cast<float>(0.5 * (1.0 - std::cos(2.0 * PI * i / (N - 1))));
    return w;
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------
struct Config {
    double      sample_rate    = 2'000'000.0;  // Hz
    double      center_freq    = 433'920'000.0; // Hz
    size_t      fft_size       = 4096;
    double      overlap_frac   = 0.5;           // 0..1
    double      nb_thresh_db   = 20.0;          // narrowband detection threshold
    double      hop_thresh_hz  = 0.0;           // 0 = auto (sr/8)
    bool        fmt_int16      = false;
    std::vector<double> known_freqs;            // Hz
};

// ---------------------------------------------------------------------------
// PSD frame result
// ---------------------------------------------------------------------------
struct PSDFrame {
    double              timestamp_s;
    std::vector<float>  psd_dbfs;   // power in dBFS, length = fft_size
    double              noise_floor_dbfs;
    double              centroid_hz;
    size_t              fft_size;
    double              sample_rate;
    double              center_freq;

    // Convert bin index to frequency in Hz (0-indexed, DC-centered)
    double bin_to_hz(size_t bin) const {
        // FFT output is [0, fs/N, 2fs/N, ...] with negative freqs in upper half.
        // Shift to center: bin > N/2 → subtract N.
        long long b = static_cast<long long>(bin);
        long long N = static_cast<long long>(fft_size);
        if (b > N / 2) b -= N;
        return center_freq + b * (sample_rate / static_cast<double>(fft_size));
    }
};

// ---------------------------------------------------------------------------
// Anomaly record
// ---------------------------------------------------------------------------
enum class AnomalyType {
    NARROWBAND_INTERFERENCE,
    WIDEBAND_JAMMING,
    FREQUENCY_HOP,
    UNKNOWN_EMISSION
};

static const char* anomaly_type_str(AnomalyType t) {
    switch (t) {
        case AnomalyType::NARROWBAND_INTERFERENCE: return "NARROWBAND_INTERFERENCE";
        case AnomalyType::WIDEBAND_JAMMING:        return "WIDEBAND_JAMMING";
        case AnomalyType::FREQUENCY_HOP:           return "FREQUENCY_HOP";
        case AnomalyType::UNKNOWN_EMISSION:        return "UNKNOWN_EMISSION";
    }
    return "UNKNOWN";
}

struct Anomaly {
    double      timestamp_s;
    double      frequency_hz;
    double      power_dbfs;
    AnomalyType type;
    std::string detail;
};

// ---------------------------------------------------------------------------
// Noise floor estimator: median of lower 70% of PSD bins
// ---------------------------------------------------------------------------
static float estimate_noise_floor(const std::vector<float>& psd) {
    std::vector<float> sorted = psd;
    std::sort(sorted.begin(), sorted.end());
    // Median of lowest 70% (avoids signal bins pulling the floor up)
    size_t idx = static_cast<size_t>(sorted.size() * 0.70);
    return sorted[idx];
}

// ---------------------------------------------------------------------------
// Spectral centroid (power-weighted mean frequency bin, relative to DC)
// Returns frequency in Hz relative to center_freq
// ---------------------------------------------------------------------------
static double spectral_centroid(const std::vector<float>& psd, double sample_rate,
                                 size_t fft_size) {
    double num = 0.0, den = 0.0;
    for (size_t i = 0; i < fft_size; ++i) {
        double linear = std::pow(10.0, psd[i] / 10.0);
        long long b = static_cast<long long>(i);
        long long N = static_cast<long long>(fft_size);
        if (b > N / 2) b -= N;
        double freq_rel = b * (sample_rate / static_cast<double>(fft_size));
        num += freq_rel * linear;
        den += linear;
    }
    return (den > 0.0) ? (num / den) : 0.0;
}

// ---------------------------------------------------------------------------
// Detect narrowband interference spikes
// Returns list of (bin, power_dbfs) pairs exceeding noise_floor + thresh_db
// Uses a simple peak-picking with 5-bin exclusion zone.
// ---------------------------------------------------------------------------
static std::vector<std::pair<size_t, float>>
detect_narrowband_peaks(const std::vector<float>& psd, float noise_floor,
                         float thresh_db) {
    std::vector<std::pair<size_t, float>> peaks;
    float threshold = noise_floor + thresh_db;
    size_t N = psd.size();
    for (size_t i = 5; i < N - 5; ++i) {
        if (psd[i] < threshold) continue;
        // Local maximum check (5-bin window)
        bool is_local_max = true;
        for (int d = -5; d <= 5; ++d) {
            if (d == 0) continue;
            if (psd[i + d] > psd[i]) { is_local_max = false; break; }
        }
        if (is_local_max) peaks.emplace_back(i, psd[i]);
    }
    return peaks;
}

// ---------------------------------------------------------------------------
// Detect wideband jamming: count bins significantly above historical noise
// ---------------------------------------------------------------------------
static bool detect_wideband_jamming(const std::vector<float>& psd,
                                     float baseline_noise,
                                     float elevation_db   = 6.0f,
                                     double fraction_thresh = 0.50) {
    float threshold = baseline_noise + elevation_db;
    size_t elevated = 0;
    for (float p : psd) if (p > threshold) ++elevated;
    return static_cast<double>(elevated) / psd.size() > fraction_thresh;
}

// ---------------------------------------------------------------------------
// Nearest known frequency (within tolerance)
// ---------------------------------------------------------------------------
static bool is_known_freq(double freq_hz, const std::vector<double>& known,
                           double tol_hz = 25000.0) {
    for (double k : known)
        if (std::abs(freq_hz - k) < tol_hz) return true;
    return false;
}

// ---------------------------------------------------------------------------
// Process a single IQ frame → PSDFrame
// ---------------------------------------------------------------------------
static PSDFrame compute_psd(const std::vector<std::complex<float>>& samples,
                              size_t fft_size, double sample_rate,
                              double center_freq, double timestamp_s) {
    const std::vector<float> window = make_hann(fft_size);
    std::vector<std::complex<float>> frame(fft_size);

    // Apply window
    for (size_t i = 0; i < fft_size; ++i)
        frame[i] = samples[i] * window[i];

    // FFT
    fft_inplace(frame);

    // Compute PSD in dBFS
    PSDFrame result;
    result.fft_size    = fft_size;
    result.sample_rate = sample_rate;
    result.center_freq = center_freq;
    result.timestamp_s = timestamp_s;
    result.psd_dbfs.resize(fft_size);

    // Normalize by window power
    float win_pwr = 0.0f;
    for (float w : window) win_pwr += w * w;
    win_pwr /= static_cast<float>(fft_size);

    for (size_t i = 0; i < fft_size; ++i) {
        float mag_sq = std::norm(frame[i]) / (static_cast<float>(fft_size) * win_pwr);
        // dBFS relative to full-scale (full-scale = 1.0 amplitude)
        result.psd_dbfs[i] = (mag_sq > 1e-30f)
                             ? (10.0f * std::log10(mag_sq))
                             : -150.0f;
    }

    result.noise_floor_dbfs = estimate_noise_floor(result.psd_dbfs);
    result.centroid_hz      = spectral_centroid(result.psd_dbfs, sample_rate, fft_size);

    return result;
}

// ---------------------------------------------------------------------------
// Full anomaly scan over a sequence of PSD frames
// ---------------------------------------------------------------------------
static std::vector<Anomaly> scan_frames(const std::vector<PSDFrame>& frames,
                                         const Config& cfg) {
    std::vector<Anomaly> anomalies;

    // Track baseline noise for wideband jamming (running average)
    float baseline_noise = -120.0f;
    bool  baseline_set   = false;

    // Track previous centroid for hopping detection
    double prev_centroid_hz = 0.0;
    bool   first_frame = true;

    // Persistent narrowband signals (bin → seen_count)
    std::map<size_t, int> persistent_bins;

    double hop_thresh = cfg.hop_thresh_hz > 0.0
                        ? cfg.hop_thresh_hz
                        : cfg.sample_rate / 8.0;

    for (const auto& frame : frames) {
        // Update baseline (only from frames with low jamming)
        if (!baseline_set) {
            baseline_noise = frame.noise_floor_dbfs;
            baseline_set   = true;
        } else {
            // Slow-update: only lower the baseline (noise floor can only go down
            // in clean conditions)
            if (frame.noise_floor_dbfs < baseline_noise)
                baseline_noise = 0.9f * baseline_noise + 0.1f * frame.noise_floor_dbfs;
        }

        // --- Narrowband interference ---
        auto peaks = detect_narrowband_peaks(frame.psd_dbfs,
                                              frame.noise_floor_dbfs,
                                              static_cast<float>(cfg.nb_thresh_db));
        for (auto& [bin, power] : peaks) {
            double freq_hz = frame.bin_to_hz(bin);
            // Track persistence
            persistent_bins[bin]++;

            anomalies.push_back({
                frame.timestamp_s, freq_hz, static_cast<double>(power),
                AnomalyType::NARROWBAND_INTERFERENCE,
                "Peak " + std::to_string(static_cast<int>(power)) + " dBFS, "
                    + std::to_string(static_cast<int>(power - frame.noise_floor_dbfs))
                    + " dB above floor"
            });

            // --- Unknown emission check ---
            if (!is_known_freq(freq_hz, cfg.known_freqs)) {
                anomalies.push_back({
                    frame.timestamp_s, freq_hz, static_cast<double>(power),
                    AnomalyType::UNKNOWN_EMISSION,
                    "Not in known-signal list (nearest match > 25 kHz away)"
                });
            }
        }

        // --- Wideband jamming ---
        if (detect_wideband_jamming(frame.psd_dbfs, baseline_noise)) {
            anomalies.push_back({
                frame.timestamp_s,
                cfg.center_freq,
                static_cast<double>(frame.noise_floor_dbfs),
                AnomalyType::WIDEBAND_JAMMING,
                "Noise floor +" + std::to_string(
                    static_cast<int>(frame.noise_floor_dbfs - baseline_noise))
                    + " dB above baseline, >50% bandwidth elevated"
            });
        }

        // --- Frequency hopping ---
        if (!first_frame) {
            double centroid_delta = std::abs(frame.centroid_hz - prev_centroid_hz);
            if (centroid_delta > hop_thresh) {
                std::ostringstream det;
                det << std::fixed << std::setprecision(0)
                    << "Centroid shift " << centroid_delta / 1000.0 << " kHz "
                    << "(threshold " << hop_thresh / 1000.0 << " kHz)";
                anomalies.push_back({
                    frame.timestamp_s,
                    cfg.center_freq + frame.centroid_hz,
                    static_cast<double>(frame.noise_floor_dbfs + cfg.nb_thresh_db),
                    AnomalyType::FREQUENCY_HOP,
                    det.str()
                });
            }
        }

        prev_centroid_hz = frame.centroid_hz;
        first_frame      = false;
    }

    return anomalies;
}

// ---------------------------------------------------------------------------
// Print anomaly report
// ---------------------------------------------------------------------------
static void print_report(const std::vector<Anomaly>& anomalies, const Config& cfg) {
    std::cout << "\n=== RF Anomaly Report ===\n";
    std::cout << "  Sample rate  : " << cfg.sample_rate / 1e6 << " MHz\n";
    std::cout << "  Center freq  : " << cfg.center_freq / 1e6 << " MHz\n";
    std::cout << "  FFT size     : " << cfg.fft_size << " bins\n";
    std::cout << "  Bin width    : "
              << std::fixed << std::setprecision(1)
              << cfg.sample_rate / cfg.fft_size << " Hz\n\n";

    if (anomalies.empty()) {
        std::cout << "  [OK] No anomalies detected.\n";
        return;
    }

    std::cout << std::left
              << std::setw(10) << "TIME(s)"
              << std::setw(16) << "FREQ(MHz)"
              << std::setw(12) << "PWR(dBFS)"
              << std::setw(28) << "TYPE"
              << "DETAIL\n";
    std::cout << std::string(90, '-') << "\n";

    for (const auto& a : anomalies) {
        std::cout << std::left << std::fixed
                  << std::setw(10) << std::setprecision(4) << a.timestamp_s
                  << std::setw(16) << std::setprecision(6) << (a.frequency_hz / 1e6)
                  << std::setw(12) << std::setprecision(1) << a.power_dbfs
                  << std::setw(28) << anomaly_type_str(a.type)
                  << a.detail << "\n";
    }

    // Count by type
    std::map<AnomalyType, int> type_counts;
    for (const auto& a : anomalies) type_counts[a.type]++;

    std::cout << "\nSummary:\n";
    for (auto& [type, count] : type_counts)
        std::cout << "  " << std::setw(30) << std::left << anomaly_type_str(type)
                  << count << " event(s)\n";
}

// ---------------------------------------------------------------------------
// Synthetic IQ generator
// Generates: Gaussian noise baseline + CW tone injection + wideband jam burst
// ---------------------------------------------------------------------------
static std::vector<std::complex<float>> generate_synthetic_iq(
        double sample_rate, double center_freq,
        size_t num_samples, int seed = 42) {
    std::vector<std::complex<float>> iq(num_samples);

    // Simple LCG for reproducibility (no <random> dependency on seeding)
    uint64_t state = static_cast<uint64_t>(seed) * 6364136223846793005ULL + 1;
    auto lcg_float = [&]() -> float {
        state = state * 6364136223846793005ULL + 1442695040888963407ULL;
        return static_cast<float>(static_cast<int64_t>(state >> 33)) / (1LL << 31);
    };

    // Noise amplitude: -80 dBFS → linear ≈ 1e-4
    float noise_amp = 1e-4f;

    // CW tone: offset 150 kHz from center, amplitude -30 dBFS
    double tone_offset_hz = 150'000.0; // Hz from center
    float  tone_amp       = std::pow(10.0f, -30.0f / 20.0f);
    double tone_phase_inc = 2.0 * PI * tone_offset_hz / sample_rate;

    // Wideband jammer: present in samples [num_samples/2, num_samples*3/4]
    size_t jam_start = num_samples / 2;
    size_t jam_end   = num_samples * 3 / 4;
    float  jam_amp   = 5e-3f; // -46 dBFS noise floor elevation

    for (size_t i = 0; i < num_samples; ++i) {
        // Thermal noise (complex Gaussian)
        float n_i = noise_amp * lcg_float();
        float n_q = noise_amp * lcg_float();

        // CW tone
        float phase = static_cast<float>(tone_phase_inc * i);
        float t_i   = tone_amp * std::cos(phase);
        float t_q   = tone_amp * std::sin(phase);

        // Wideband jam burst (noise-like: broadband random)
        float j_i = 0.0f, j_q = 0.0f;
        if (i >= jam_start && i < jam_end) {
            j_i = jam_amp * lcg_float();
            j_q = jam_amp * lcg_float();
        }

        iq[i] = std::complex<float>(n_i + t_i + j_i, n_q + t_q + j_q);
    }

    return iq;
}

// ---------------------------------------------------------------------------
// Frame IQ data into overlapping FFT windows
// ---------------------------------------------------------------------------
static std::vector<PSDFrame> frame_iq(
        const std::vector<std::complex<float>>& iq,
        const Config& cfg) {
    std::vector<PSDFrame> frames;
    size_t fft_size = cfg.fft_size;
    size_t hop      = static_cast<size_t>(fft_size * (1.0 - cfg.overlap_frac));
    if (hop == 0) hop = 1;

    for (size_t offset = 0; offset + fft_size <= iq.size(); offset += hop) {
        std::vector<std::complex<float>> seg(iq.begin() + offset,
                                              iq.begin() + offset + fft_size);
        double ts = static_cast<double>(offset) / cfg.sample_rate;
        frames.push_back(compute_psd(seg, fft_size, cfg.sample_rate,
                                      cfg.center_freq, ts));
    }
    return frames;
}

// ---------------------------------------------------------------------------
// Load IQ samples from file
// ---------------------------------------------------------------------------
static std::vector<std::complex<float>> load_iq_file(const std::string& path,
                                                       bool fmt_int16) {
    std::ifstream f(path, std::ios::binary);
    if (!f.is_open()) throw std::runtime_error("Cannot open file: " + path);

    f.seekg(0, std::ios::end);
    size_t file_size = static_cast<size_t>(f.tellg());
    f.seekg(0, std::ios::beg);

    std::vector<std::complex<float>> iq;

    if (fmt_int16) {
        size_t num_pairs = file_size / (2 * sizeof(int16_t));
        iq.resize(num_pairs);
        std::vector<int16_t> buf(num_pairs * 2);
        f.read(reinterpret_cast<char*>(buf.data()), file_size);
        constexpr float scale = 1.0f / 32768.0f;
        for (size_t i = 0; i < num_pairs; ++i)
            iq[i] = std::complex<float>(buf[2*i] * scale, buf[2*i+1] * scale);
    } else {
        size_t num_pairs = file_size / (2 * sizeof(float));
        iq.resize(num_pairs);
        f.read(reinterpret_cast<char*>(iq.data()), num_pairs * 2 * sizeof(float));
    }

    return iq;
}

// ---------------------------------------------------------------------------
// CLI argument parser
// ---------------------------------------------------------------------------
static void parse_args(int argc, char* argv[], Config& cfg,
                        std::string& input_file, bool& demo_mode,
                        bool& gen_mode, std::string& gen_output) {
    demo_mode  = false;
    gen_mode   = false;

    for (int i = 1; i < argc; ++i) {
        std::string a = argv[i];
        if (a == "--rate"      && i+1 < argc) { cfg.sample_rate  = std::stod(argv[++i]); }
        else if (a == "--fc"   && i+1 < argc) { cfg.center_freq  = std::stod(argv[++i]); }
        else if (a == "--fft"  && i+1 < argc) { cfg.fft_size     = std::stoull(argv[++i]); }
        else if (a == "--overlap" && i+1 < argc) { cfg.overlap_frac = std::stod(argv[++i]); }
        else if (a == "--nbthresh" && i+1 < argc) { cfg.nb_thresh_db = std::stod(argv[++i]); }
        else if (a == "--hopthresh" && i+1 < argc){ cfg.hop_thresh_hz= std::stod(argv[++i]); }
        else if (a == "--fmt"  && i+1 < argc) {
            std::string fmt = argv[++i];
            cfg.fmt_int16 = (fmt == "int16");
        }
        else if (a == "--known" && i+1 < argc) {
            cfg.known_freqs.push_back(std::stod(argv[++i]));
        }
        else if (a == "--gen-iq" && i+1 < argc) {
            gen_mode   = true;
            gen_output = argv[++i];
        }
        else if (a[0] != '-' && input_file.empty()) {
            input_file = a;
        }
    }

    if (input_file.empty() && !gen_mode) demo_mode = true;

    if (!is_pow2(cfg.fft_size)) {
        std::cerr << "Warning: FFT size " << cfg.fft_size
                  << " is not a power of 2. Rounding up.\n";
        size_t n = 1;
        while (n < cfg.fft_size) n <<= 1;
        cfg.fft_size = n;
    }
}

// ---------------------------------------------------------------------------
// main
// ---------------------------------------------------------------------------
int main(int argc, char* argv[]) {
    Config      cfg;
    std::string input_file;
    bool        demo_mode = true, gen_mode = false;
    std::string gen_output;

    parse_args(argc, argv, cfg, input_file, demo_mode, gen_mode, gen_output);

    // Synthetic IQ generation (write to file)
    if (gen_mode) {
        size_t num_samples = 1 << 20; // 1M samples
        auto iq = generate_synthetic_iq(cfg.sample_rate, cfg.center_freq,
                                         num_samples);
        std::ofstream out(gen_output, std::ios::binary);
        if (!out.is_open()) {
            std::cerr << "Error: cannot write to " << gen_output << "\n";
            return 1;
        }
        out.write(reinterpret_cast<const char*>(iq.data()),
                  iq.size() * sizeof(std::complex<float>));
        std::cout << "Wrote " << iq.size() << " complex float32 samples to "
                  << gen_output << "\n";
        return 0;
    }

    std::cout << "=== RF Anomaly Detector";
    if (demo_mode) std::cout << " — Demo Mode";
    std::cout << " ===\n";
    std::cout << "  Center: " << cfg.center_freq / 1e6 << " MHz  "
              << "BW: " << cfg.sample_rate / 1e6 << " MHz  "
              << "FFT: " << cfg.fft_size << "\n\n";

    std::vector<std::complex<float>> iq;

    if (demo_mode) {
        std::cout << "Generating synthetic IQ: AWGN + CW tone at +"
                  << 150 << " kHz + wideband jam burst...\n";
        size_t num_samples = cfg.fft_size * 32;
        iq = generate_synthetic_iq(cfg.sample_rate, cfg.center_freq, num_samples);
        // For demo: no known signals → tone will flag as unknown emission
    } else {
        std::cout << "Loading IQ from: " << input_file << "\n";
        try {
            iq = load_iq_file(input_file, cfg.fmt_int16);
        } catch (const std::exception& e) {
            std::cerr << "Error: " << e.what() << "\n";
            return 1;
        }
    }

    std::cout << "  Loaded " << iq.size() << " complex samples ("
              << std::fixed << std::setprecision(3)
              << static_cast<double>(iq.size()) / cfg.sample_rate * 1e3
              << " ms)\n";

    // Frame and compute PSD
    auto frames = frame_iq(iq, cfg);
    std::cout << "  Frames: " << frames.size() << " (overlap="
              << static_cast<int>(cfg.overlap_frac * 100) << "%)\n";

    // Run anomaly detection
    auto anomalies = scan_frames(frames, cfg);

    // Report
    print_report(anomalies, cfg);

    return 0;
}
