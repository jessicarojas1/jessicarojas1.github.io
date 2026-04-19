import SwiftUI

struct WatchContentView: View {
    @Environment(WatchSessionManager.self) private var session

    var body: some View {
        if session.isRoundActive {
            WatchActiveRoundView()
        } else {
            WatchIdleView()
        }
    }
}

struct WatchIdleView: View {
    var body: some View {
        VStack(spacing: 8) {
            Image(systemName: "figure.golf")
                .font(.system(size: 36))
                .foregroundStyle(.green)
            Text("GolfTrack")
                .font(.headline)
            Text("Open the iPhone app\nto start a round")
                .font(.caption2)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
        }
        .padding()
    }
}
