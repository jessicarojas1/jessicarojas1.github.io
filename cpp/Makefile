# ============================================================================
#  Makefile — CPP Defense & Signal-Processing Tool Collection
#  ---------------------------------------------------------------------------
#  Builds 12 standalone C++17 command-line utilities into ./bin.
#  Each tool is a single translation unit; there is no shared library and no
#  link step between tools. Only `aes-vault` has an external dependency
#  (OpenSSL); `entropy-scanner` and `log-correlator` need pthreads.
#
#  Quick start:
#     make            # build every tool that can build on this host
#     make aes-vault  # build one tool
#     make clean      # remove ./bin
#     make install PREFIX=/usr/local   # install binaries to $(PREFIX)/bin
#
#  Toolchain: g++ (or clang++) with C++17. Verified with g++ 13.3.0.
#  See docs/DEPLOYMENT.md and deployments/LOCAL_DEVELOPMENT.md for details.
# ============================================================================

CXX      ?= g++
CXXSTD   ?= -std=c++17
OPT      ?= -O2
WARN     ?= -Wall -Wextra
CXXFLAGS ?= $(CXXSTD) $(OPT) $(WARN)

BIN      := bin
PREFIX   ?= /usr/local

# ---- External dependencies -------------------------------------------------
# aes-vault links OpenSSL (libssl + libcrypto). Override if headers/libs live
# somewhere non-standard, e.g.  make OPENSSL_LIBS="-L/opt/ssl/lib -lssl -lcrypto"
OPENSSL_LIBS ?= -lssl -lcrypto

# entropy-scanner and log-correlator use std::thread / std::async.
PTHREAD ?= -pthread

# ---- Tool list (target name -> source) -------------------------------------
# NOTE: memory-scanner is Linux-only (requires /proc + ptrace); it will fail to
# compile on non-Linux hosts by design (static_assert). It is included in `all`.
PORTABLE_TOOLS := \
	mil1553-sim \
	packet-analyzer \
	rf-anomaly \
	gps-detector \
	arinc429-decoder \
	cui-classifier \
	zt-policy \
	yara-lite

THREADED_TOOLS := entropy-scanner log-correlator
OPENSSL_TOOLS  := aes-vault
LINUX_TOOLS    := memory-scanner

ALL_TOOLS := $(OPENSSL_TOOLS) $(THREADED_TOOLS) $(LINUX_TOOLS) $(PORTABLE_TOOLS)

.PHONY: all portable static test clean install help version $(ALL_TOOLS)

all: $(ALL_TOOLS)
	@echo "Built: $(ALL_TOOLS)"

# Everything except the Linux-only and OpenSSL-linked tools — handy on macOS
# without OpenSSL, or when /proc is unavailable.
portable: $(PORTABLE_TOOLS) $(THREADED_TOOLS)

# Statically-linked portable + threaded tools for air-gap / offline install.
# aes-vault (OpenSSL) and memory-scanner (Linux-runtime) are intentionally
# excluded — static OpenSSL and glibc NSS are non-portable. See
# deployments/AIRGAPPED.md. Binaries land in ./$(BIN) with a '-static' suffix.
static: | $(BIN)
	@for t in $(PORTABLE_TOOLS) $(THREADED_TOOLS); do \
		case $$t in \
		  entropy-scanner) src=entropy-scanner/entropy_scanner.cpp; extra="$(PTHREAD)";; \
		  log-correlator)  src=log-correlator/log_correlator.cpp;   extra="$(PTHREAD)";; \
		  mil1553-sim)     src=mil-std-1553/mil1553_sim.cpp;        extra="";; \
		  packet-analyzer) src=packet-analyzer/packet_analyzer.cpp; extra="";; \
		  rf-anomaly)      src=rf-anomaly/rf_anomaly.cpp;           extra="";; \
		  gps-detector)    src=gps-antispoofing/gps_detector.cpp;   extra="";; \
		  arinc429-decoder)src=arinc429/arinc429_decoder.cpp;       extra="";; \
		  cui-classifier)  src=cui-classifier/cui_classifier.cpp;   extra="";; \
		  zt-policy)       src=zt-policy-engine/zt_policy.cpp;       extra="";; \
		  yara-lite)       src=yara-lite/yara_lite.cpp;             extra="";; \
		esac; \
		echo "static: $$t"; \
		$(CXX) $(CXXFLAGS) -static $$extra $$src -o $(BIN)/$$t-static || exit 1; \
	done
	@echo "Static builds in ./$(BIN) (suffix -static)"

# Build everything, then run the smoke/contract test harness.
test: all
	@SKIP_BUILD=1 ./tests/run_tests.sh

$(BIN):
	@mkdir -p $(BIN)

# ---- Per-tool rules --------------------------------------------------------
aes-vault: | $(BIN)
	$(CXX) $(CXXFLAGS) aes-vault/aes_vault.cpp $(OPENSSL_LIBS) -o $(BIN)/aes-vault

entropy-scanner: | $(BIN)
	$(CXX) $(CXXFLAGS) $(PTHREAD) entropy-scanner/entropy_scanner.cpp -o $(BIN)/entropy-scanner

log-correlator: | $(BIN)
	$(CXX) $(CXXFLAGS) $(PTHREAD) log-correlator/log_correlator.cpp -o $(BIN)/log-correlator

memory-scanner: | $(BIN)
	$(CXX) $(CXXFLAGS) memory-scanner/memory_scanner.cpp -o $(BIN)/memory-scanner

mil1553-sim: | $(BIN)
	$(CXX) $(CXXFLAGS) mil-std-1553/mil1553_sim.cpp -o $(BIN)/mil1553-sim

packet-analyzer: | $(BIN)
	$(CXX) $(CXXFLAGS) packet-analyzer/packet_analyzer.cpp -o $(BIN)/packet-analyzer

rf-anomaly: | $(BIN)
	$(CXX) $(CXXFLAGS) rf-anomaly/rf_anomaly.cpp -o $(BIN)/rf-anomaly

gps-detector: | $(BIN)
	$(CXX) $(CXXFLAGS) gps-antispoofing/gps_detector.cpp -o $(BIN)/gps-detector

arinc429-decoder: | $(BIN)
	$(CXX) $(CXXFLAGS) arinc429/arinc429_decoder.cpp -o $(BIN)/arinc429-decoder

cui-classifier: | $(BIN)
	$(CXX) $(CXXFLAGS) cui-classifier/cui_classifier.cpp -o $(BIN)/cui-classifier

zt-policy: | $(BIN)
	$(CXX) $(CXXFLAGS) zt-policy-engine/zt_policy.cpp -o $(BIN)/zt-policy

yara-lite: | $(BIN)
	$(CXX) $(CXXFLAGS) yara-lite/yara_lite.cpp -o $(BIN)/yara-lite

# ---- Housekeeping ----------------------------------------------------------
install: all
	@mkdir -p $(DESTDIR)$(PREFIX)/bin
	@cp $(addprefix $(BIN)/,$(ALL_TOOLS)) $(DESTDIR)$(PREFIX)/bin/
	@echo "Installed $(words $(ALL_TOOLS)) tools to $(DESTDIR)$(PREFIX)/bin"

clean:
	@rm -rf $(BIN)
	@echo "Removed $(BIN)"

# Record the compiler version used for a build (reproducibility metadata).
version:
	@$(CXX) --version | head -1
	@echo "CXXFLAGS = $(CXXFLAGS)"

help:
	@echo "Targets:"
	@echo "  all       build all 12 tools into ./$(BIN) (default)"
	@echo "  portable  build only tools with no OpenSSL/Linux-/proc dependency"
	@echo "  static    statically link portable+threaded tools (air-gap) -> ./$(BIN)/*-static"
	@echo "  test      build all, then run tests/run_tests.sh (smoke/contract suite)"
	@echo "  version   print compiler version + CXXFLAGS (build metadata)"
	@echo "  <tool>    build one tool, e.g. 'make aes-vault'"
	@echo "  install   copy binaries to \$$(PREFIX)/bin  (PREFIX=$(PREFIX))"
	@echo "  clean     remove ./$(BIN)"
	@echo ""
	@echo "Tools: $(ALL_TOOLS)"
