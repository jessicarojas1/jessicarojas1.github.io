import SwiftUI

struct ContentView: View {
    @Environment(ActiveRoundManager.self) private var roundManager
    @State private var showActiveRound = false

    var body: some View {
        Group {
#if os(macOS)
            NavigationSplitView {
                List {
                    Label("Home",     systemImage: "house.fill")
                    Label("History",  systemImage: "clock.arrow.circlepath")
                    Label("Stats",    systemImage: "chart.bar.fill")
                    Label("Courses",  systemImage: "map.fill")
                    Label("Rules",    systemImage: "book.fill")
                    Label("Devices",  systemImage: "applewatch")
                }
                .navigationSplitViewColumnWidth(min: 160, ideal: 180)
            } detail: {
                NavigationStack { HomeView(showActiveRound: $showActiveRound) }
            }
#else
            TabView {
                NavigationStack { HomeView(showActiveRound: $showActiveRound) }
                    .tabItem { Label("Home",    systemImage: "house.fill") }
                NavigationStack { RoundHistoryView() }
                    .tabItem { Label("History", systemImage: "clock.arrow.circlepath") }
                NavigationStack { StatsView() }
                    .tabItem { Label("Stats",   systemImage: "chart.bar.fill") }
                NavigationStack { CourseLibraryView() }
                    .tabItem { Label("Courses", systemImage: "map.fill") }
                NavigationStack { GolfRulesView() }
                    .tabItem { Label("Rules",   systemImage: "book.fill") }
                NavigationStack { DeviceConnectionView() }
                    .tabItem { Label("Devices", systemImage: "applewatch") }
            }
#endif
        }
        .sheet(isPresented: $showActiveRound) {
            ActiveRoundView()
        }
        .onChange(of: roundManager.isActive) { _, active in
            if active { showActiveRound = true }
        }
    }
}
