import SwiftUI
#if os(iOS)
import WatchConnectivity
#endif

struct DeviceConnectionView: View {
    @Environment(WatchConnectivityManager.self) private var watchManager

    var body: some View {
        List {
            Section("Apple Watch") {
                watchRow
            }

            Section("Garmin Watch") {
                garminRow
            }

            Section("Android Wear") {
                androidRow
            }

            Section("Music") {
                musicRow
            }

            Section("Golf") {
                NavigationLink(destination: ClubProfileSetupView()) {
                    HStack {
                        Image(systemName: "figure.golf")
                            .foregroundStyle(.green)
                            .frame(width: 28)
                        VStack(alignment: .leading, spacing: 2) {
                            Text("Club Distances")
                                .font(.body)
                            Text("Set your distances for club recommendations")
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                    }
                }
            }
        }
        .navigationTitle("Settings")
#if os(iOS)
        .navigationBarTitleDisplayMode(.inline)
#endif
    }

    // MARK: - Rows

    private var watchRow: some View {
        HStack {
            Image(systemName: "applewatch")
                .foregroundStyle(.primary)
                .frame(width: 28)
            VStack(alignment: .leading, spacing: 2) {
                Text("Apple Watch")
                    .font(.body)
#if os(iOS)
                Text(watchStatusText)
                    .font(.caption)
                    .foregroundStyle(watchStatusColor)
#else
                Text("Requires iPhone + watchOS app")
                    .font(.caption)
                    .foregroundStyle(.secondary)
#endif
            }
            Spacer()
#if os(iOS)
            Circle()
                .fill(watchStatusColor)
                .frame(width: 10, height: 10)
#endif
        }
    }

    private var garminRow: some View {
        HStack {
            Image(systemName: "sportscourt")
                .foregroundStyle(.orange)
                .frame(width: 28)
            VStack(alignment: .leading, spacing: 2) {
                Text("Garmin Watch")
                    .font(.body)
                Text("Connect via Garmin Connect app")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer()
            Link("Get App", destination: URL(string: "https://apps.apple.com/app/garmin-connect/id583446403")!)
                .font(.caption)
                .foregroundStyle(.blue)
        }
    }

    private var androidRow: some View {
        HStack {
            Image(systemName: "antenna.radiowaves.left.and.right")
                .foregroundStyle(.green)
                .frame(width: 28)
            VStack(alignment: .leading, spacing: 2) {
                Text("Android Wear / Wear OS")
                    .font(.body)
                Text("Install GolfTrack on Google Play")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer()
            Image(systemName: "arrow.up.forward.square")
                .foregroundStyle(.secondary)
                .font(.caption)
        }
    }

    private var musicRow: some View {
        HStack {
            Image(systemName: "music.note")
                .foregroundStyle(.pink)
                .frame(width: 28)
            VStack(alignment: .leading, spacing: 2) {
                Text("Music Controls")
                    .font(.body)
                Text("Mini player shows while scoring a round")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer()
            Image(systemName: "checkmark.circle.fill")
                .foregroundStyle(.green)
                .font(.caption)
        }
    }

    // MARK: - Watch status helpers

#if os(iOS)
    private var watchStatusText: String {
        guard WCSession.isSupported() else { return "Not supported on this device" }
        if !watchManager.isPaired    { return "No Apple Watch paired" }
        if !watchManager.isInstalled { return "GolfTrack not on Watch" }
        if watchManager.isReachable  { return "Connected" }
        return "Watch app installed — not in range"
    }

    private var watchStatusColor: Color {
        guard WCSession.isSupported(),
              watchManager.isPaired,
              watchManager.isInstalled else { return .secondary }
        return watchManager.isReachable ? .green : .orange
    }
#endif
}
