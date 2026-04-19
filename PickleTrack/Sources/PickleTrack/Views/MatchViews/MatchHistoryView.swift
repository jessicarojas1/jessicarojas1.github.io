import SwiftUI
import SwiftData

struct MatchHistoryView: View {
    @Query(filter: #Predicate<PBMatch> { $0.isComplete },
           sort: \PBMatch.date, order: .reverse)
    private var matches: [PBMatch]

    var body: some View {
        Group {
            if matches.isEmpty {
                ContentUnavailableView(
                    "No Matches Yet",
                    systemImage: "sportscourt",
                    description: Text("Completed matches appear here.")
                )
            } else {
                List(matches) { match in
                    NavigationLink(destination: MatchDetailView(match: match)) {
                        MatchRow(match: match)
                    }
                }
            }
        }
        .navigationTitle("History")
    }
}

// MARK: - Row

struct MatchRow: View {
    let match: PBMatch

    private var winnerName: String {
        guard let w = match.winner else { return "" }
        return w == 1 ? match.team1DisplayName : match.team2DisplayName
    }

    private var winnerColor: Color { match.winner == 1 ? .blue : .orange }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(match.displayName)
                    .font(.headline)
                Spacer()
                Text(match.date.formatted(date: .abbreviated, time: .omitted))
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            HStack(spacing: 8) {
                Text(match.format.rawValue)
                    .font(.caption2)
                    .padding(.horizontal, 6).padding(.vertical, 2)
                    .background(Color.secondary.opacity(0.15))
                    .clipShape(Capsule())

                Text(match.scoreString)
                    .font(.caption.monospacedDigit())
                    .foregroundStyle(.secondary)

                Spacer()

                if let _ = match.winner {
                    Label(winnerName, systemImage: "trophy.fill")
                        .font(.caption.bold())
                        .foregroundStyle(winnerColor)
                        .lineLimit(1)
                }
            }
        }
        .padding(.vertical, 4)
    }
}

// MARK: - Detail

struct MatchDetailView: View {
    let match: PBMatch

    private var sortedGames: [PBGame] { match.games.sorted { $0.gameNumber < $1.gameNumber } }

    var body: some View {
        List {
            Section("Match Info") {
                LabeledContent("Format", value: match.format.rawValue)
                LabeledContent("Date", value: match.date.formatted(date: .long, time: .omitted))
                LabeledContent("Best of", value: "\(match.bestOf)")
                LabeledContent("Game length", value: "To \(match.gameLength)")
            }

            Section("Result") {
                HStack {
                    VStack {
                        Text(match.team1DisplayName).font(.caption).foregroundStyle(.secondary)
                        Text("\(match.team1GamesWon)").font(.largeTitle.bold()).foregroundStyle(.blue)
                    }
                    .frame(maxWidth: .infinity)
                    Text("–").font(.title2).foregroundStyle(.secondary)
                    VStack {
                        Text(match.team2DisplayName).font(.caption).foregroundStyle(.secondary)
                        Text("\(match.team2GamesWon)").font(.largeTitle.bold()).foregroundStyle(.orange)
                    }
                    .frame(maxWidth: .infinity)
                }
                .padding(.vertical, 4)
            }

            Section("Games") {
                ForEach(sortedGames) { game in
                    HStack {
                        Text("Game \(game.gameNumber)")
                            .font(.callout)
                        Spacer()
                        Text("\(game.team1Score)–\(game.team2Score)")
                            .font(.callout.bold().monospacedDigit())
                            .foregroundStyle(game.winner == 1 ? Color.blue : game.winner == 2 ? Color.orange : Color.primary)
                    }
                }
            }
        }
        .navigationTitle(match.displayName)
#if os(iOS)
        .navigationBarTitleDisplayMode(.inline)
#endif
    }
}
