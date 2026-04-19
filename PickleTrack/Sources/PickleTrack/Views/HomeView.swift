import SwiftUI
import SwiftData

struct HomeView: View {
    @Environment(ActiveMatchManager.self) private var matchManager
    @Binding var showActiveMatch: Bool

    @Query(filter: #Predicate<PBMatch> { $0.isComplete },
           sort: \PBMatch.date, order: .reverse)
    private var matches: [PBMatch]

    @State private var showNewMatch = false

    private var winRate: Double {
        guard !matches.isEmpty else { return 0 }
        return Double(matches.filter { $0.winner == 1 }.count) / Double(matches.count) * 100
    }

    private var recentMatches: [PBMatch] { Array(matches.prefix(5)) }

    var body: some View {
        ScrollView {
            VStack(spacing: 20) {
                // Hero card
                VStack(spacing: 6) {
                    Image(systemName: "sportscourt.fill")
                        .font(.system(size: 40))
                        .foregroundStyle(.green)
                    Text("PickleTrack")
                        .font(.largeTitle.bold())
                    if matches.isEmpty {
                        Text("Track every match. Improve every game.")
                            .font(.callout)
                            .foregroundStyle(.secondary)
                    } else {
                        Text(String(format: "Win rate: %.0f%%  •  %d matches", winRate, matches.count))
                            .font(.callout)
                            .foregroundStyle(.secondary)
                    }
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 28)
                .background(
                    LinearGradient(colors: [.green.opacity(0.15), .clear], startPoint: .top, endPoint: .bottom)
                )
                .clipShape(RoundedRectangle(cornerRadius: 18))
                .padding(.horizontal)

                // Action buttons
                VStack(spacing: 12) {
                    Button {
                        if matchManager.isActive {
                            showActiveMatch = true
                        } else {
                            showNewMatch = true
                        }
                    } label: {
                        Label(matchManager.isActive ? "Resume Match" : "New Match",
                              systemImage: matchManager.isActive ? "play.fill" : "plus")
                            .font(.headline)
                            .frame(maxWidth: .infinity)
                            .padding()
                            .background(Color.green)
                            .foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 14))
                    }
                    .padding(.horizontal)
                }

                // Recent matches
                if !recentMatches.isEmpty {
                    VStack(alignment: .leading, spacing: 10) {
                        Text("Recent Matches")
                            .font(.headline)
                            .padding(.horizontal)

                        ForEach(recentMatches) { match in
                            MatchRow(match: match)
                                .padding(.horizontal)
                            if match.id != recentMatches.last?.id { Divider().padding(.horizontal) }
                        }
                    }
                    .padding(.vertical, 14)
                    .background(.regularMaterial)
                    .clipShape(RoundedRectangle(cornerRadius: 16))
                    .padding(.horizontal)
                }
            }
            .padding(.vertical)
        }
        .navigationTitle("PickleTrack")
        .sheet(isPresented: $showNewMatch) {
            NewMatchSetupView()
        }
        .onChange(of: matchManager.isActive) { _, active in
            if active { showActiveMatch = true }
        }
    }
}
