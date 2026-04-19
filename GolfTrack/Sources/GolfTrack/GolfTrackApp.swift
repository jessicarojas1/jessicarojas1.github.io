import SwiftUI
import SwiftData

struct GolfTrackApp: App {
    let container: ModelContainer
    @State private var roundManager    = ActiveRoundManager()
    @State private var locationManager = LocationManager()
    @State private var watchManager    = WatchConnectivityManager.shared
    @State private var musicManager    = MusicManager()
    @State private var clubProfile     = ClubProfile.shared

    init() {
        do {
            container = try ModelContainer(for: Round.self, HoleScore.self, CustomCourse.self)
        } catch {
            fatalError("Failed to create ModelContainer: \(error)")
        }
    }

    var body: some Scene {
        WindowGroup {
            ContentView()
                .modelContainer(container)
                .environment(roundManager)
                .environment(locationManager)
                .environment(watchManager)
                .environment(musicManager)
                .environment(clubProfile)
        }
#if os(macOS)
        .windowStyle(.titleBar)
        .defaultSize(width: 1100, height: 720)
#endif
    }
}
