import SwiftUI
import WatchConnectivity

@main
struct GolfTrackWatchApp: App {
    @State private var watchSession = WatchSessionManager()

    var body: some Scene {
        WindowGroup {
            WatchContentView()
                .environment(watchSession)
        }
    }
}
