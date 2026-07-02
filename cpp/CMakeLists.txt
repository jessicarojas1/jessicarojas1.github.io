# ============================================================================
#  CMakeLists.txt — CPP Defense & Signal-Processing Tool Collection
#  ---------------------------------------------------------------------------
#  Optional CMake build (the canonical build is the top-level Makefile).
#  Produces 12 standalone C++17 CLI executables. Each tool is one .cpp file
#  with no inter-tool linkage.
#
#  Build:
#     cmake -S . -B build -DCMAKE_BUILD_TYPE=Release
#     cmake --build build -j
#     # binaries land in build/
#
#  aes-vault requires OpenSSL. If OpenSSL is not found, that single target is
#  skipped and the other 11 tools still build.
# ============================================================================
cmake_minimum_required(VERSION 3.16)
project(cpp_tools LANGUAGES CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)
set(CMAKE_CXX_EXTENSIONS ON)   # tools use __builtin_* / GNU intrinsics

if(NOT CMAKE_BUILD_TYPE)
  set(CMAKE_BUILD_TYPE Release)
endif()

find_package(Threads REQUIRED)

# ---- Portable tools (no external deps) -------------------------------------
add_executable(mil1553-sim       mil-std-1553/mil1553_sim.cpp)
add_executable(packet-analyzer   packet-analyzer/packet_analyzer.cpp)
add_executable(rf-anomaly        rf-anomaly/rf_anomaly.cpp)
add_executable(gps-detector      gps-antispoofing/gps_detector.cpp)
add_executable(arinc429-decoder  arinc429/arinc429_decoder.cpp)
add_executable(cui-classifier    cui-classifier/cui_classifier.cpp)
add_executable(zt-policy         zt-policy-engine/zt_policy.cpp)
add_executable(yara-lite         yara-lite/yara_lite.cpp)

# ---- Threaded tools --------------------------------------------------------
add_executable(entropy-scanner   entropy-scanner/entropy_scanner.cpp)
target_link_libraries(entropy-scanner PRIVATE Threads::Threads)

add_executable(log-correlator    log-correlator/log_correlator.cpp)
target_link_libraries(log-correlator PRIVATE Threads::Threads)

# ---- Linux-only tool (needs /proc + ptrace) --------------------------------
if(CMAKE_SYSTEM_NAME STREQUAL "Linux")
  add_executable(memory-scanner  memory-scanner/memory_scanner.cpp)
else()
  message(STATUS "memory-scanner skipped: requires Linux /proc and ptrace")
endif()

# ---- OpenSSL-linked tool ---------------------------------------------------
find_package(OpenSSL)
if(OpenSSL_FOUND)
  add_executable(aes-vault       aes-vault/aes_vault.cpp)
  target_link_libraries(aes-vault PRIVATE OpenSSL::SSL OpenSSL::Crypto)
else()
  message(WARNING "aes-vault skipped: OpenSSL not found (install libssl-dev)")
endif()

install(TARGETS
  mil1553-sim packet-analyzer rf-anomaly gps-detector arinc429-decoder
  cui-classifier zt-policy yara-lite entropy-scanner log-correlator
  RUNTIME DESTINATION bin OPTIONAL)
