import SwiftUI

struct ContentView: View {
    @Environment(ActiveMatchManager.self) private var matchManager
    @State private var showActiveMatch = false

    var body: some View {
        Group {
#if os(macOS)
            NavigationSplitView {
                List {
                    Label("Home",    systemImage: "house.fill")
                    Label("History", systemImage: "clock.arrow.circlepath")
                    Label("Stats",   systemImage: "chart.bar.fill")
                    Label("Courts",  systemImage: "sportscourt.fill")
                    Label("Rules",   systemImage: "book.fill")
                }
                .navigationSplitViewColumnWidth(min: 160, ideal: 180)
            } detail: {
                NavigationStack { HomeView(showActiveMatch: $showActiveMatch) }
            }
#else
            TabView {
                NavigationStack { HomeView(showActiveMatch: $showActiveMatch) }
                    .tabItem { Label("Home",    systemImage: "house.fill") }
                NavigationStack { MatchHistoryView() }
                    .tabItem { Label("History", systemImage: "clock.arrow.circlepath") }
                NavigationStack { StatsView() }
                    .tabItem { Label("Stats",   systemImage: "chart.bar.fill") }
                NavigationStack { NearbyCourtView() }
                    .tabItem { Label("Courts",  systemImage: "sportscourt.fill") }
                NavigationStack { RulesView() }
                    .tabItem { Label("Rules",   systemImage: "book.fill") }
            }
            .tint(.green)
#endif
        }
        .sheet(isPresented: $showActiveMatch) {
            ActiveMatchView()
        }
    }
}
