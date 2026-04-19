import SwiftUI
import SwiftData

struct PickleTrackApp: App {
    let container: ModelContainer
    @State private var matchManager   = ActiveMatchManager()
    @State private var locationMgr    = LocationManager()
    @State private var courtSearch    = CourtSearchManager()

    init() {
        do {
            container = try ModelContainer(for: PBMatch.self, PBGame.self)
        } catch {
            fatalError("Failed to create ModelContainer: \(error)")
        }
    }

    var body: some Scene {
        WindowGroup {
            ContentView()
                .modelContainer(container)
                .environment(matchManager)
                .environment(locationMgr)
                .environment(courtSearch)
        }
#if os(macOS)
        .windowStyle(.titleBar)
        .defaultSize(width: 1000, height: 680)
#endif
    }
}
